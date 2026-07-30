<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Conversation;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\DeliveryFailureLineItem;
use Modules\KapsoWhatsApp\Services\MessageBody;
use Modules\KapsoWhatsApp\Services\PhoneNumber;
use Modules\KapsoWhatsApp\Services\SystemUser;

/**
 * `whatsapp.message.sent` and `whatsapp.message.failed` are deliberately NOT
 * deduped on wamid by WebhookController (unlike `whatsapp.message.received`):
 * a send and its later failure share one wamid, and deduping on it would
 * swallow the failure after the send was recorded. That means two concurrent
 * deliveries of the *same* event can both reach this job — the controller's
 * `X-Idempotency-Key` cache check is a has/put pair with a window between
 * them, not a lock — and, separately, Kapso does not guarantee that `sent`
 * arrives before `failed` for the same message: a single job retry
 * (`$tries = 3`) is enough to invert them even when Kapso sends them in
 * order, and some error classes (e.g. 131047, outside the 24h window) may
 * never produce a `sent` event at all. Each branch below therefore carries
 * its own idempotency guard rather than relying on a read-then-write check,
 * and `recordFailure()` does not require a pre-existing row:
 *
 * - `recordForeignSend()` (a `sent` event for an unknown wamid) relies on
 *   the unique index on `kapso_whatsapp_messages.wamid`, the same way
 *   ProcessInboundMessage does: the thread and the dedupe row are written in
 *   one transaction, and a unique-key violation rolls the whole thing back.
 * - `recordFailure()` for an *already-known* row uses an atomic
 *   `UPDATE ... WHERE status IS NULL OR status <> 'failed'` claim (mirroring
 *   `events_dispatched_at` in ProcessInboundMessage): only the delivery that
 *   actually flips the status gets to create the line item, since the row
 *   already exists and a fresh unique-key insert can't serve as the dedupe
 *   guard the way it does for a brand-new row.
 * - `recordFailure()` for an *unknown* row (the sibling `sent` has not been
 *   processed yet, or never will be) creates the row itself, already marked
 *   failed, using the same unique-index-plus-transaction pattern as
 *   `recordForeignSend()`. If that insert loses a race — to a duplicate
 *   `failed` delivery, or to the matching `sent` event committing first — the
 *   loser applies the atomic UPDATE claim to whichever row won instead of
 *   just deferring to it, so a `sent` that merely got there first can never
 *   leave a message that actually failed looking delivered. Once a row is
 *   marked failed, `handle()`'s `sent` branch no-ops on any wamid it already
 *   knows regardless of status, so nothing downstream of that can revive it.
 */
class ReconcileOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $accountId;
    public $event;
    public $payload;
    public $tries = 3;

    public function __construct($accountId, $event, array $payload)
    {
        $this->accountId = $accountId;
        $this->event     = $event;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = KapsoAccount::find($this->accountId);

        if (!$account) {
            return;
        }

        $message = $this->payload['message'] ?? [];
        $wamid   = $message['id'] ?? null;

        if (!$wamid) {
            return;
        }

        // A fast-path read, not the correctness guard: this only saves the
        // common case its own transaction. The actual guarantee against a
        // concurrent duplicate delivery lives in recordForeignSend()'s
        // unique-index catch and recordFailure()'s atomic claim below.
        $known = KapsoMessage::where('wamid', $wamid)->first();

        if ($this->event === 'whatsapp.message.failed') {
            $this->recordFailure($account, $known, $message, $wamid);

            return;
        }

        // whatsapp.message.sent
        if ($known) {
            // Our own send (thread_id + send_state accepted, written by
            // SendReplyMessage), one already reconciled, or one a `failed`
            // event for this same wamid already recorded — possibly before
            // this `sent` event was even processed. markOwnSendSent() is the
            // only thing that ever writes to this row from here, and it
            // never overwrites a row already `failed`, regardless of what
            // this `sent` event says: that is what keeps a message that
            // actually failed from ever being flipped back to looking
            // delivered.
            $this->markOwnSendSent($known);

            return;
        }

        $this->recordForeignSend($account, $message, $wamid);
    }

    /**
     * A send we did not make: during the parallel run this is an agent replying
     * from the n8n bridge, or someone using Kapso's own inbox. Recording it
     * keeps FreeScout's history complete instead of showing a one-sided thread.
     */
    protected function recordForeignSend(KapsoAccount $account, array $message, $wamid)
    {
        [$e164, $conversationId] = $this->resolveOutboundConversation($account, $message, $wamid, 'sent');

        if (!$conversationId) {
            // Already logged by resolveOutboundConversation() -- both the
            // "no usable recipient identity at all" case and the "resolved a
            // number/kapso_conversation_id but no matching conversation"
            // case.
            return;
        }

        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return;
        }

        $body = MessageBody::extract($message);

        try {
            \DB::transaction(function () use ($account, $conversation, $message, $wamid, $body, $e164) {
                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id         = null;
                // No real FreeScout agent authored this thread. Core assumes
                // every TYPE_MESSAGE thread has a creator -- the print view
                // dereferences created_by_user_cached with no null guard --
                // so it is attributed to a dedicated synthetic user rather
                // than left null, the same mechanism the bundled Workflows
                // module uses for its own generated threads.
                $thread->created_by_user_id = SystemUser::get()->id;
                $thread->type            = Thread::TYPE_MESSAGE;
                $thread->status          = Thread::STATUS_ACTIVE;
                $thread->state           = Thread::STATE_PUBLISHED;
                // Always our own escaped text, never a copy of an
                // agent-composed body: this method creates a brand-new
                // Thread for every foreign send and never attaches a wamid
                // to a pre-existing one -- so THIS thread, specifically, is
                // always safe for ProcessInboundMessage::applyReaction()'s
                // preg_replace() to run over.
                //
                // That said, this is no longer the only path that can set a
                // wamid's `thread_id` -- since Task 4,
                // SendReplyMessage::claimAndSend() also sets `thread_id` (and
                // later `wamid`) on rows that point at agent-composed reply
                // threads, real WYSIWYG HTML from FreeScout's own editor, not
                // escaped text like this thread's. A customer reacting to an
                // agent's WhatsApp reply therefore resolves, via
                // KapsoMessage::threadForWamid(), to that reply thread, and
                // applyReaction() runs its preg_replace()/append over editor
                // HTML. That is still safe -- the `u`-modifier's null-return
                // is guarded there (falls back to the original body instead
                // of blanking it), the reaction emoji is escaped via e()
                // before being appended, and appending the reaction paragraph
                // to our own reply's body is the desired behaviour, not a
                // bug -- but the safety no longer rests on "no other path can
                // set a wamid's thread_id"; it rests on applyReaction()'s own
                // guards. See the Task 6 review for the full trace.
                $thread->body            = nl2br(e($body))
                    .'<p><em>'.__('Sent outside FreeScout').'</em></p>';
                $thread->source_via      = Thread::PERSON_USER;
                $thread->source_type     = Thread::SOURCE_TYPE_API;
                $thread->customer_id     = $conversation->customer_id;
                $thread->save();

                // The unique index on `wamid` is the real dedupe guard: if a
                // concurrent delivery of this same `sent` event committed
                // between the `$known` lookup in handle() and here, this
                // throws and the whole transaction — including the thread
                // just created above — rolls back, so no orphan duplicate
                // thread is left behind.
                KapsoMessage::create([
                    'account_id'            => $account->id,
                    'conversation_id'       => $conversation->id,
                    'thread_id'             => $thread->id,
                    'wamid'                 => $wamid,
                    'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                    'direction'             => KapsoMessage::DIRECTION_OUTBOUND,
                    'status'                => $message['kapso']['status'] ?? 'sent',
                    'is_reaction'           => false,
                    'contact_phone'         => $e164,
                ]);

                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_USER;
                $conversation->setPreview($body);
                $conversation->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $raced = KapsoMessage::where('wamid', $wamid)->first();

            if (!$raced) {
                throw $e;
            }

            // A concurrent delivery committed first — either a duplicate of
            // this same `sent` event, or a `failed` event for the same
            // wamid that got there first (recordFailureForUnknownSend()
            // below runs the mirror-image of this transaction). Either way,
            // our speculative thread/conversation update above was rolled
            // back with the transaction, and the winning delivery's row —
            // whatever its status — already reflects the correct outcome.
            // In particular, a `sent` losing to a `failed` here must never
            // try to resurrect the row as "sent"; simply deferring is what
            // keeps that message looking failed rather than delivered.
        }
    }

    /**
     * A `sent` event for a wamid this module already has a row for. Only
     * acts when that row is our own accepted send -- `thread_id` and
     * `send_state` set by SendReplyMessage's claimAndSend() -- and is not
     * already `failed`; any other known row (a foreign send already
     * reconciled, a duplicate delivery of this same event, ...) is left
     * untouched.
     *
     * The marker's meaning (THREAD_META_SENT_AT, see its docblock) is "this
     * reply is delivered-and-healthy", not merely "Kapso accepted this
     * part" -- so stamping it takes two independent checks, run in this
     * order:
     *
     * 1. The atomic `UPDATE ... WHERE status IS NULL` status claim. Its
     *    *form* is unchanged from before, but its *result* no longer gates
     *    the stamp below -- it exists only to keep the status write itself
     *    idempotent/atomic (the row starts with `status` NULL, since
     *    SendReplyMessage never writes that column). Gating the stamp on
     *    `$claimed` used to mean a crash between this claim committing and
     *    the stamp below (reviewer M1) lost the marker forever: the
     *    duplicate `sent` delivery Kapso eventually redelivers would find
     *    `status` already `sent`, fail this same claim, and give up without
     *    ever stamping. Un-gating it is what lets that redelivery finish the
     *    job the crashed run didn't.
     * 2. The sibling-failure gate just below: skip the stamp when *any* row
     *    sharing this `thread_id` -- any part of the same multi-part reply,
     *    including this row itself -- is `failed`. This is what stops a
     *    partially-failed reply from ever showing the marker (one part
     *    accepted does not mean the reply as a whole is healthy), and, for
     *    this row's own failures, it is also what stops a redelivered
     *    `sent` from resurrecting a marker applyFailureToRow() already
     *    cleared: once *this* row is failed, the guard above already bails
     *    (`$known->status === 'failed'`) before reaching here, but a
     *    *sibling* part failing does not touch this row's own `status` --
     *    it stays `sent` -- so without this gate the trio above would let a
     *    redelivery straight through.
     *
     * Neither check alone is a race guard by itself any more; together they
     * are what keeps "failed always wins" true no matter which of `sent` /
     * `failed`, for which part, commits first or gets redelivered.
     */
    protected function markOwnSendSent(KapsoMessage $known)
    {
        if (!$known->thread_id || $known->send_state !== KapsoMessage::SEND_STATE_ACCEPTED || $known->status === 'failed') {
            return;
        }

        // Idempotent/atomic status write only -- see the docblock above for
        // why its result must not gate the stamp below.
        KapsoMessage::where('id', $known->id)
            ->whereNull('status')
            ->update(['status' => 'sent']);

        $hasFailedSibling = KapsoMessage::where('thread_id', $known->thread_id)
            ->where(function ($query) {
                $query->where('send_state', KapsoMessage::SEND_STATE_FAILED)
                    ->orWhere('status', 'failed');
            })
            ->exists();

        if ($hasFailedSibling) {
            return;
        }

        $thread = Thread::find($known->thread_id);

        if (!$thread) {
            return;
        }

        $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, now()->toIso8601String());
        $thread->save();
    }

    /**
     * Delivery failures arrive asynchronously and are not guaranteed to
     * arrive after their matching `sent` event. A silently dropped failure
     * is the worst outcome for a helpdesk — it leaves nothing recorded, and
     * a subsequently-processed `sent` for the same wamid would then record
     * the message as an ordinary successful outbound thread. So this method
     * does not require a pre-existing row: it surfaces the failure either
     * way.
     */
    protected function recordFailure(KapsoAccount $account, $known, array $message, $wamid)
    {
        $summary = $this->failureSummary($message);

        if ($known) {
            $this->applyFailureToRow($known, $summary);

            return;
        }

        $this->recordFailureForUnknownSend($account, $message, $wamid, $summary);
    }

    /**
     * A `failed` event whose wamid we've never seen: the sibling `sent`
     * event for the same message has not been processed yet, or never
     * arrives at all for error classes that fail before a `sent` webhook
     * would ever fire (e.g. 131047, re-engagement outside the 24h window).
     * Resolves the conversation exactly like recordForeignSend() does (via
     * the recipient phone in the payload) and creates the row directly,
     * already marked failed, so agents can see both what was attempted and
     * why it failed.
     */
    protected function recordFailureForUnknownSend(KapsoAccount $account, array $message, $wamid, $summary)
    {
        [$e164, $conversationId] = $this->resolveOutboundConversation($account, $message, $wamid, 'failed');

        $conversation = $conversationId ? Conversation::find($conversationId) : null;

        if (!$conversation) {
            // Already logged by resolveOutboundConversation() unless a
            // conversation id resolved but pointed at a since-deleted
            // Conversation row -- an edge case not worth its own log line.
            return;
        }

        $body = MessageBody::extract($message);

        try {
            \DB::transaction(function () use ($account, $conversation, $wamid, $body, $e164, $summary) {
                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id         = null;
                // See recordForeignSend(): no real agent authored this
                // thread, so it is attributed to the module's synthetic user
                // rather than left null (core's print view is fatal on a
                // TYPE_MESSAGE thread with no created_by_user_id).
                $thread->created_by_user_id = SystemUser::get()->id;
                $thread->type            = Thread::TYPE_MESSAGE;
                $thread->status          = Thread::STATUS_ACTIVE;
                $thread->state           = Thread::STATE_PUBLISHED;
                $thread->body            = nl2br(e($body))
                    .'<p><em>'.__('Attempted outside FreeScout, delivery failed').'</em></p>';
                $thread->source_via      = Thread::PERSON_USER;
                $thread->source_type     = Thread::SOURCE_TYPE_API;
                $thread->customer_id     = $conversation->customer_id;
                $thread->save();

                // The unique index on `wamid` is the dedupe guard here too:
                // a concurrent delivery racing us — another `failed` for the
                // same wamid, or the matching `sent` event, whichever had
                // not yet committed when handle() read $known as null —
                // throws on this insert, and the whole transaction
                // (including the thread just created above) rolls back. The
                // catch below then applies the failure to whichever row
                // won, rather than just deferring to it, so a `sent` that
                // merely got there first can never leave this message
                // looking delivered.
                KapsoMessage::create([
                    'account_id'            => $account->id,
                    'conversation_id'       => $conversation->id,
                    'thread_id'             => $thread->id,
                    'wamid'                 => $wamid,
                    'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                    'direction'             => KapsoMessage::DIRECTION_OUTBOUND,
                    'status'                => 'failed',
                    'error'                 => $summary,
                    'is_reaction'           => false,
                    'contact_phone'         => $e164,
                ]);

                // No marker to clear on this path: `thread_id` here points at
                // the thread just created above, in this same transaction --
                // brand new, so it cannot already carry THREAD_META_SENT_AT
                // (only markOwnSendSent() ever stamps it, and only for a row
                // that already reached the `accepted` send state, which
                // nothing on this path ever does before landing here failed).
                DeliveryFailureLineItem::create($conversation, $summary);

                // Deliberately not updated here, unlike recordForeignSend():
                // last_reply_at / last_reply_from / the preview describe
                // what the customer actually received, and this message was
                // never delivered to them.
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $raced = KapsoMessage::where('wamid', $wamid)->first();

            if (!$raced) {
                throw $e;
            }

            // A concurrent delivery for this same wamid committed first —
            // either another `failed` delivery (nothing left to do) or a
            // `sent` delivery that merely won the create-row race
            // (recordForeignSend() creating the row while this transaction
            // was still being built). Apply the failure to whatever row now
            // exists rather than just deferring to it: that is what
            // guarantees the outcome never depends on which of `sent` /
            // `failed` happened to commit its insert first.
            $this->applyFailureToRow($raced, $summary);
        }
    }

    /**
     * Marks an existing `KapsoMessage` row failed and posts the line item,
     * guarded by an atomic claim so a duplicate/concurrent delivery of the
     * failure — or one that merely lost the create-row race against the
     * matching `sent` event in recordFailureForUnknownSend() above — applies
     * this at most once. MySQL/MariaDB's row-level locking on UPDATE
     * serialises concurrent attempts and always evaluates the WHERE clause
     * against the latest committed data, so this is safe even when two
     * workers race here at the same instant.
     *
     * Also clears the "Sent via WhatsApp" marker on this row's reply thread,
     * if any (reviewer I1(a)): WhatsApp's common failure order is
     * accepted-then-failed, so markOwnSendSent() may already have stamped
     * THREAD_META_SENT_AT for this exact row before this later failure
     * arrived. The marker must never coexist with a recorded failure for
     * the same reply -- see THREAD_META_SENT_AT's own docblock for the
     * invariant this maintains.
     */
    protected function applyFailureToRow(KapsoMessage $known, $summary)
    {
        $claimed = KapsoMessage::where('id', $known->id)
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '<>', 'failed');
            })
            ->update(['status' => 'failed', 'error' => $summary]);

        if (!$claimed) {
            return;
        }

        if ($known->thread_id) {
            // Thread has no removeMeta(); Thread::getMeta() reads the meta
            // array with isset(), so writing the value as null makes it read
            // back exactly as absent -- the same effect as removing the key.
            // Safe to run even when no marker was ever stamped for this
            // thread (e.g. this row's own send never reached `accepted`
            // before failing): setMeta()+save() then just writes the same
            // already-absent value back.
            $thread = Thread::find($known->thread_id);

            if ($thread) {
                $thread->setMeta(KapsoMessage::THREAD_META_SENT_AT, null);
                $thread->save();
            }
        }

        if (!$known->conversation_id) {
            return;
        }

        $conversation = Conversation::find($known->conversation_id);

        if (!$conversation) {
            return;
        }

        DeliveryFailureLineItem::create($conversation, $summary);
    }

    /**
     * Builds the human-readable error summary from
     * `message.kapso.statuses[0].errors`, stored on the row and quoted in
     * the line item.
     */
    protected function failureSummary(array $message)
    {
        $errors = $message['kapso']['statuses'][0]['errors'] ?? [];
        $parts  = [];

        foreach ($errors as $error) {
            $parts[] = trim(($error['code'] ?? '').' '.($error['title'] ?? '').' — '.($error['message'] ?? ''));
        }

        return $parts ? implode('; ', $parts) : __('Delivery failed');
    }

    /**
     * Most recent conversation this account has exchanged messages with the
     * given number. Used to attach both a foreign send and an unknown
     * delivery failure to the right conversation.
     *
     * Deliberately does not filter on conversation status: a foreign send or
     * a delayed failure can arrive after an agent has closed the
     * conversation, and this still attaches it to that same closed
     * conversation rather than dropping it or opening a new one. That is a
     * defensible tradeoff for Stage 1, not an oversight.
     */
    protected function resolveConversationId(KapsoAccount $account, $e164)
    {
        return KapsoMessage::where('contact_phone', $e164)
            ->where('account_id', $account->id)
            ->whereNotNull('conversation_id')
            ->orderBy('id', 'desc')
            ->value('conversation_id');
    }

    /**
     * Resolves the conversation a `sent`/`failed` outbound event should
     * attach to. Kapso's own docs state that `phone_number`, `from`, `to`
     * and `wa_id` are not always present on every event, so `message.to`
     * alone is not a reliable key -- and previously, recordForeignSend()
     * simply gave up (with no log line at all) whenever it was missing,
     * silently dropping every reply sent from elsewhere for that event.
     *
     * Tries, in order:
     *
     *  1. `message.to`, normalised, matched via resolveConversationId()
     *     against this module's own message history for the account.
     *  2. `conversation.phone_number` -- a second, independent field Kapso
     *     may populate even when `to` is missing -- matched the same way.
     *  3. `kapso_conversation_id`, written on every row this module creates
     *     and otherwise never read anywhere: the one identity that survives
     *     even when the payload carries no usable phone number at all.
     *
     * Every dead end is logged (with $label distinguishing a `sent` event
     * from a `failed` one) rather than silently dropped, so a vanishing
     * event always leaves a trace to investigate.
     *
     * @return array{0: ?string, 1: ?int} [$e164, $conversationId]
     */
    protected function resolveOutboundConversation(KapsoAccount $account, array $message, $wamid, $label)
    {
        $defaultCountryCode = PhoneNumber::configuredDefaultCountryCode();

        $e164 = PhoneNumber::toE164($message['to'] ?? null, $defaultCountryCode)
            ?: PhoneNumber::toE164($this->payload['conversation']['phone_number'] ?? null, $defaultCountryCode);

        $conversationId = $e164 ? $this->resolveConversationId($account, $e164) : null;

        if (!$conversationId) {
            $kapsoConversationId = $this->payload['conversation']['id'] ?? null;

            if ($kapsoConversationId) {
                $conversationId = KapsoMessage::where('kapso_conversation_id', $kapsoConversationId)
                    ->where('account_id', $account->id)
                    ->whereNotNull('conversation_id')
                    ->orderBy('id', 'desc')
                    ->value('conversation_id');
            }
        }

        if (!$conversationId) {
            if ($e164) {
                \Log::info("[KapsoWhatsApp] Outbound {$label} event for an unknown conversation, dropped", ['wamid' => $wamid]);
            } else {
                \Log::info("[KapsoWhatsApp] Outbound {$label} event with no usable recipient identity (no `to`, no `conversation.phone_number`, no matching `kapso_conversation_id`), dropped", ['wamid' => $wamid]);
            }
        }

        return [$e164, $conversationId];
    }
}
