<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Attachment;
use App\Conversation;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Mailbox;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\ConversationResolver;
use Modules\KapsoWhatsApp\Services\CustomerResolver;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\MessageBody;
use Modules\KapsoWhatsApp\Services\PhoneNumber;

class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $accountId;
    public $payload;
    public $tries = 3;

    public function __construct($accountId, array $payload)
    {
        $this->accountId = $accountId;
        $this->payload   = $payload;
    }

    public function handle()
    {
        $account = KapsoAccount::find($this->accountId);

        if (!$account) {
            \Log::warning('[KapsoWhatsApp] Inbound job for missing account '.$this->accountId);

            return;
        }

        $message = $this->payload['message'] ?? [];
        $wamid   = $message['id'] ?? null;

        if (!$wamid) {
            return;
        }

        $existing = KapsoMessage::where('wamid', $wamid)->first();

        if ($existing) {
            // Either a genuine duplicate delivery (events already dispatched,
            // dispatchPendingEvents() below is then a no-op) or a retry of a
            // previous attempt that committed the conversation/thread/dedupe
            // row but crashed or threw before the events fired. Recover
            // rather than silently dropping CustomerCreatedConversation /
            // CustomerReplied for a message that never got them.
            $this->dispatchPendingEvents($existing);

            return;
        }

        $e164 = PhoneNumber::toE164($message['from'] ?? null, PhoneNumber::configuredDefaultCountryCode());

        if (!$e164) {
            \Log::warning('[KapsoWhatsApp] Inbound message without a usable sender number', ['wamid' => $wamid]);

            return;
        }

        if (($message['type'] ?? '') === 'reaction') {
            $this->applyReaction($account, $message, $wamid, $e164);

            return;
        }

        $mailbox = Mailbox::find($account->mailbox_id);

        if (!$mailbox) {
            \Log::error('[KapsoWhatsApp] Account '.$account->id.' points at missing mailbox '.$account->mailbox_id);

            return;
        }

        $contactName = $this->payload['conversation']['kapso']['contact_name']
            ?? ($this->payload['conversation']['contact_name'] ?? null);

        $customer = (new CustomerResolver())->resolve($e164, $contactName);

        $body = $this->body($message);

        // Media is downloaded here, before the transaction opens: it's
        // network I/O (an HTTP round-trip to Kapso) and must never run while
        // holding open a database transaction. The bytes are kept in memory
        // and only turned into an Attachment (a disk write) after the
        // transaction below has committed — see the comment past the
        // try/catch for why.
        $mediaInfo  = $this->mediaInfo($message);
        $mediaBytes = $mediaInfo ? (new KapsoClient($account))->downloadMedia($mediaInfo['url']) : null;

        $conversation = null;
        $thread       = null;
        $kapsoMessage = null;

        try {
            \DB::transaction(function () use (
                $account, $mailbox, $customer, $message, $wamid, $body, $e164,
                &$conversation, &$thread, &$kapsoMessage
            ) {
                $resolved     = (new ConversationResolver())->resolve($customer, $mailbox, Conversation::subjectFromText($body['raw']));
                $conversation = $resolved['conversation'];
                $isNew        = $resolved['is_new'];

                $thread = new Thread();
                $thread->conversation_id = $conversation->id;
                $thread->user_id         = $conversation->user_id;
                $thread->type            = Thread::TYPE_CUSTOMER;
                $thread->status          = $conversation->status;
                $thread->state           = Thread::STATE_PUBLISHED;
                $thread->body            = $body['html'];
                $thread->source_via      = Thread::PERSON_CUSTOMER;
                $thread->source_type     = Thread::SOURCE_TYPE_API;
                $thread->customer_id     = $customer->id;
                $thread->created_by_customer_id = $customer->id;
                if ($isNew) {
                    $thread->first = true;
                }
                $thread->save();

                // The unique index on `wamid` is the real dedupe guard: if a
                // concurrent job for the same message committed between our
                // lookup above and here, this throws and the whole
                // transaction — including the thread just created above —
                // rolls back, so no orphan duplicate thread is left behind.
                $kapsoMessage = KapsoMessage::create([
                    'account_id'            => $account->id,
                    'conversation_id'       => $conversation->id,
                    'thread_id'             => $thread->id,
                    'wamid'                 => $wamid,
                    'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                    'direction'             => KapsoMessage::DIRECTION_INBOUND,
                    'status'                => $message['kapso']['status'] ?? 'received',
                    'is_reaction'           => false,
                    'contact_phone'         => $e164,
                ]);

                // Match core's ordering (Thread.php:1170/1227, FetchEmails.php:1246-1247/1318):
                // last_reply_at, last_reply_from and the preview are set on the
                // conversation *before* the Eventy filter runs, so a filter
                // inspecting those fields sees the up-to-date values instead of
                // stale ones. setPreview() takes the raw text, not the
                // HTML-escaped body — Helper::textPreview() strips tags but never
                // decodes entities, so an escaped body would leave literal
                // "&amp;"/"&#039;" in the conversation list.
                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
                $conversation->setPreview($body['raw']);

                $conversation = \Eventy::filter(
                    $isNew ? 'conversation.created_by_customer' : 'conversation.customer_replied',
                    $conversation, $thread, $customer
                );

                $conversation->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $raced = KapsoMessage::where('wamid', $wamid)->first();

            if (!$raced) {
                throw $e;
            }

            // A concurrent job for the same wamid committed first; our
            // speculative conversation/thread work above was rolled back
            // with the transaction. Defer to that job's row and make sure
            // its events still get dispatched even if it never got the
            // chance to. $mediaBytes (if any) is simply discarded here: it
            // was only ever held in memory, never written to disk, so the
            // losing job leaves nothing behind to clean up.
            $this->dispatchPendingEvents($raced);

            return;
        }

        // The attachment is created only now, after the conversation/thread/
        // message writes above have durably committed. Laravel 5.5 has no
        // DB::afterCommit() (that arrived in Laravel 8), so this runs as
        // plain code straight after the transaction call returns instead.
        // Creating it earlier (inside the transaction, as a first attempt at
        // this did) meant any rollback — e.g. the concurrent-wamid race
        // handled above — would discard the attachment's DB row while the
        // file Attachment::create() already wrote to disk stayed behind
        // forever, since filesystem writes are not covered by a SQL
        // rollback. By construction there is nothing left to roll back at
        // this point: $thread and $kapsoMessage are already committed. If
        // attachment creation itself still fails here, the message must
        // still survive — attachMedia() falls back to the same "attachment
        // could not be retrieved" note the download-failure path produces,
        // and never sets has_attachments without a matching attachment row.
        if ($mediaInfo) {
            $attachmentId = $this->attachMedia($mediaInfo, $mediaBytes, $thread);

            if ($attachmentId) {
                $kapsoMessage->attachment_id = $attachmentId;
                $kapsoMessage->save();
            }
        }

        $mailbox->updateFoldersCounters();

        // Fired after the transaction commits: a listener throwing here must
        // not roll back the message that was already safely persisted.
        $this->dispatchPendingEvents($kapsoMessage);
    }

    /**
     * Fires the core Laravel events and Eventy hooks at most once per
     * message, even across retries and concurrent workers.
     * `events_dispatched_at` is inbound-only and is claimed atomically: the
     * `UPDATE ... WHERE events_dispatched_at IS NULL` below is the only place
     * the column is written, and it is a compare-and-set — only the worker
     * whose UPDATE actually matches a row may dispatch. This closes two
     * duplicate-fire paths a plain read-then-write would leave open: (1) the
     * race-recovery `catch` block calling this for a message whose *winning*
     * job has committed but not yet reached its own dispatch call (marker
     * still NULL at that moment), and (2) a throwing listener causing the
     * job to retry — without the atomic claim, a NULL marker would look
     * identical to "never attempted" on every retry and re-fire everything
     * each time. A row that already has the marker set (genuine duplicate
     * delivery, or already claimed by another worker) makes the UPDATE
     * affect 0 rows and this is a no-op. The guarantee is at-most-once, not
     * exactly-once: a worker that dies between the claim committing and the
     * event() calls below returning loses the events permanently, since
     * nothing ever re-drives a row whose marker is already set. That is the
     * deliberate trade for never double-firing notifications.
     */
    protected function dispatchPendingEvents(KapsoMessage $kapsoMessage)
    {
        // events_dispatched_at is inbound-only: `kapso_whatsapp_messages` also
        // holds outbound rows (written by ReconcileOutboundMessage), whose
        // marker is always NULL. Without this guard, a future caller could
        // fire CustomerReplied for an agent-sent message.
        if ($kapsoMessage->direction !== KapsoMessage::DIRECTION_INBOUND) {
            return;
        }

        // Reaction rows are inbound too, so the guard above does not exclude
        // them. applyReaction() never calls this method directly (its caller
        // in handle() returns first), but a *redelivered* reaction wamid hits
        // the "$existing" dedupe branch near the top of handle(), which
        // unconditionally calls this method for any already-seen wamid. Since
        // a reaction's events_dispatched_at is never claimed at creation, that
        // path would otherwise be free to claim it now and fire
        // CustomerCreatedConversation/CustomerReplied against the *target*
        // message's thread for something that is not a new customer message.
        //
        // This is a dedicated `is_reaction` column rather than a sentinel
        // value in `status`: `status` is written straight from
        // `$message['kapso']['status']` for ordinary inbound messages — an
        // unvalidated pass-through of whatever Kapso's webhook payload
        // contains. If Kapso ever sent `kapso.status === 'reaction'` for a
        // genuine message, guarding on that string would silently swallow
        // its customer events. `is_reaction` is set only by this file's own
        // two write paths and is never derived from Kapso's payload.
        if ($kapsoMessage->is_reaction) {
            return;
        }

        // Resolved before the claim so that a missing thread/conversation
        // bails out without ever marking the row as dispatched — leaving it
        // eligible for a future retry to try again, instead of permanently
        // losing the events. Only the claim itself, and the event dispatch
        // it guards, should sit inside the unrecoverable exposure window.
        $thread       = $kapsoMessage->thread_id ? Thread::find($kapsoMessage->thread_id) : null;
        $conversation = $thread ? $thread->conversation : null;

        if (!$thread || !$conversation) {
            return;
        }

        $customer = $conversation->customer;

        $claimed = KapsoMessage::where('id', $kapsoMessage->id)
            ->whereNull('events_dispatched_at')
            ->update(['events_dispatched_at' => now()]);

        if (!$claimed) {
            return; // another worker already owns these events
        }

        // Inbound over a webhook bypasses the mail-fetch pipeline, so
        // nothing else raises these. Without them, notifications, workflows
        // and auto-replies silently never run. The claim above has already
        // been committed, so a throwing listener (FreeScout's own listeners
        // for these events are synchronous) must not be allowed to fail this
        // job and drive a retry that re-enters and re-fires everything.
        try {
            if ($thread->first) {
                event(new CustomerCreatedConversation($conversation, $thread));
                \Eventy::action('conversation.created_by_customer', $conversation, $thread, $customer);
            } else {
                event(new CustomerReplied($conversation, $thread));
                \Eventy::action('conversation.customer_replied', $conversation, $thread, $customer);
            }
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] A listener threw while dispatching events for message id '.$kapsoMessage->id, [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Reactions reference another message by id. Without a local wamid -> thread
     * map there is nothing to attach them to, so an unplaceable reaction is
     * dropped rather than surfacing as a stray thread.
     */
    protected function applyReaction(KapsoAccount $account, array $message, $wamid, $e164)
    {
        $targetWamid = $message['reaction']['message_id'] ?? null;
        $emoji       = $message['reaction']['emoji'] ?? '';

        $threadId = KapsoMessage::threadForWamid($targetWamid);

        if (!$threadId) {
            \Log::info('[KapsoWhatsApp] Reaction for an unknown message, dropped', [
                'wamid'        => $wamid,
                'target_wamid' => $targetWamid,
            ]);

            return;
        }

        $thread = Thread::find($threadId);

        if (!$thread) {
            // The dedupe row survived but the thread it pointed at is gone
            // (e.g. deleted since). Log this the same way as the "unknown
            // target" branch above rather than returning silently, since
            // this is the more surprising of the two cases: the reaction's
            // target was once known locally.
            \Log::warning('[KapsoWhatsApp] Reaction target thread no longer exists, dropped', [
                'wamid'        => $wamid,
                'target_wamid' => $targetWamid,
                'thread_id'    => $threadId,
            ]);

            return;
        }

        // preg_replace() with the `u` modifier returns null (not the
        // original string) when its subject isn't valid UTF-8, which would
        // otherwise blank the thread body on save. $thread->body here is not
        // always our own escaped HTML any more: since Task 4,
        // SendReplyMessage::claimAndSend() also sets `thread_id` (and later
        // `wamid`) on rows pointing at agent-composed reply threads, so a
        // reaction to an agent's WhatsApp reply resolves here, via
        // threadForWamid(), to real WYSIWYG editor HTML. Still safe: this
        // guard's original-body fallback, plus the e() on the emoji below,
        // hold regardless of which kind of thread this is -- but the safety
        // no longer rests on "the body is always our own escaped HTML", it
        // rests on these guards. See the Task 6 review for the full trace.
        $stripped = preg_replace('#<p class="kapsowhatsapp-reaction">.*?</p>#u', '', $thread->body);
        $stripped = $stripped === null ? $thread->body : $stripped;

        if ($emoji === '') {
            // Removing a reaction: strip any previous marker.
            $thread->body = $stripped;
        } else {
            $thread->body = $stripped.'<p class="kapsowhatsapp-reaction">'.__('Reaction:').' '.e($emoji).'</p>';
        }

        $thread->save();

        try {
            KapsoMessage::create([
                'account_id'            => $account->id,
                'conversation_id'       => $thread->conversation_id,
                'thread_id'             => $thread->id,
                'wamid'                 => $wamid,
                'kapso_conversation_id' => $this->payload['conversation']['id'] ?? null,
                'direction'             => KapsoMessage::DIRECTION_INBOUND,
                'is_reaction'           => true,
                'contact_phone'         => $e164,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Same race the main inbound path guards against: the unique
            // index on `wamid` is the real dedupe guard, and a concurrent
            // job for this same reaction wamid can commit between the
            // "$existing" lookup at the top of handle() and here. If that
            // row is now present, the winning job already applied this
            // reaction to the thread (the body update just above is
            // idempotent), so there is nothing left for this attempt to do.
            // Unlike the main path, there is no dispatchPendingEvents() to
            // defer to: reactions never fire customer events.
            if (!KapsoMessage::where('wamid', $wamid)->exists()) {
                throw $e;
            }
        }
    }

    /**
     * Extracts the media location/name/type Kapso attached to an inbound
     * message, or null when the message carries no media. Pure data
     * shaping — no I/O — so it can run before the network download and
     * before the DB transaction alike.
     */
    protected function mediaInfo(array $message)
    {
        if (empty($message['kapso']['has_media'])) {
            return null;
        }

        $mediaData = $message['kapso']['media_data'] ?? [];

        return [
            'url'       => $message['kapso']['media_url'] ?? ($mediaData['url'] ?? null),
            'file_name' => $mediaData['filename'] ?? 'attachment',
            'mime_type' => $mediaData['content_type'] ?? 'application/octet-stream',
        ];
    }

    /**
     * Turns already-downloaded media bytes into a FreeScout attachment on
     * $thread. Takes the bytes rather than downloading them itself: the
     * download is network I/O and must happen before `\DB::transaction()`
     * opens, while this runs after it has committed (it only needs the
     * thread's id, which the transaction already assigned, plus a couple of
     * fast local writes). Returns the created attachment id, or null when
     * there was no media or the download failed — either way $thread is
     * annotated so the message itself is never lost.
     *
     * $type is passed as null (not `Attachment::typeNameToInt($mimeType)`):
     * that helper keys off bare type names like "image", not full mime
     * types like "image/jpeg", so it would silently misclassify every
     * attachment as TYPE_OTHER. Passing null lets `Attachment::create()`
     * fall back to its own `detectType()`, which parses the mime type
     * correctly — the same convention core and ApiWebhooks use.
     */
    protected function attachMedia(array $mediaInfo, $bytes, Thread $thread)
    {
        if ($bytes === null) {
            $this->noteMediaFailure($thread, $mediaInfo['file_name']);

            return null;
        }

        // This runs after the transaction has committed (see the caller), so
        // there is no rollback to lean on any more: an exception here (e.g.
        // the DB write half of Attachment::create() failing after the file
        // half already landed on disk) must be caught here, or it would bail
        // out of handle() entirely and skip dispatchPendingEvents() for a
        // message that is otherwise already safely persisted.
        try {
            $attachment = Attachment::create(
                $mediaInfo['file_name'],
                $mediaInfo['mime_type'],
                null,
                $bytes,
                null,
                false,
                $thread->id,
                null
            );
        } catch (\Exception $e) {
            \Log::error('[KapsoWhatsApp] Attachment::create() threw', [
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);
            $attachment = null;
        }

        if (!$attachment) {
            $this->noteMediaFailure($thread, $mediaInfo['file_name']);

            return null;
        }

        $thread->has_attachments = true;
        $thread->save();

        return $attachment->id;
    }

    /**
     * Losing an attachment must never lose the message: instead of failing
     * the job, the thread is still created/saved with its text, plus a note
     * naming the file that could not be retrieved.
     */
    protected function noteMediaFailure(Thread $thread, $fileName)
    {
        \Log::warning('[KapsoWhatsApp] Could not retrieve attachment', [
            'thread_id' => $thread->id,
            'file_name' => $fileName,
        ]);

        $thread->body .= '<p><em>'
            .__('Attachment could not be retrieved:').' '.e($fileName)
            .'</em></p>';
        $thread->save();
    }

    /**
     * Prefer the typed text; fall back to Kapso's rendered representation so
     * unsupported types (location, contacts, interactive) still carry
     * content. Returns both the raw text and the HTML-escaped body:
     * `Conversation::subjectFromText()` and `Conversation::setPreview()` only
     * strip tags — they never decode HTML entities — so building the subject
     * or preview from an already-escaped body would leave literal
     * "&amp;"/"&#039;" in conversation subjects, previews and email
     * notification headers. The thread body, on the other hand, must stay
     * escaped: the text is attacker-controlled and is rendered unescaped in
     * the conversation view.
     */
    protected function body(array $message)
    {
        $raw = MessageBody::extract($message);

        return [
            'raw'  => $raw,
            'html' => nl2br(e($raw, true)),
        ];
    }
}
