<?php

namespace Modules\KapsoWhatsApp\Jobs;

use App\Attachment;
use App\Conversation;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\DeliveryFailureLineItem;
use Modules\KapsoWhatsApp\Services\KapsoClient;

/**
 * Delivers one published agent-reply thread to WhatsApp: chunks the body and
 * attaches each attachment as its own "part", claims each part with a
 * send-once DB row, POSTs it through Kapso's Meta proxy, and records the
 * wamid Kapso hands back. Runs after core's undo window
 * (Listeners\SendReplyToWhatsApp queues it with a delay), so it is handed a
 * thread **id**, not a model -- the thread must be re-fetched fresh, and may
 * by then have been undone (back to STATE_DRAFT) or otherwise no longer be
 * eligible to send. That is a legitimate race, not an error: guards() bails
 * silently (an info log, not a warning/error) whenever any of them fail.
 *
 * Claim semantics (see claimAndSend()): each part is identified by a unique
 * (thread_id, part_key) row. A duplicate-key insert means either "already
 * sent" (skip) or "a sending/failed leftover from an earlier attempt of this
 * same reply" (re-claim and retry). This is at-least-once, not
 * exactly-once: if the process crashes after Kapso accepts the HTTP request
 * but before this job writes the wamid back, a retry will re-send that one
 * part -- there is no way to distinguish "Kapso never got it" from "Kapso
 * got it but we died before recording that". This is accepted, documented
 * behaviour (see the plan's Global Constraints), not a bug to "fix" by
 * weakening the claim.
 */
class SendReplyMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * WhatsApp text messages cap at 4096 chars; chunking at 4000 leaves
     * headroom rather than relying on the exact limit.
     */
    const TEXT_CHUNK_LIMIT = 4000;

    public $threadId;
    public $tries = 3;

    public function __construct($threadId)
    {
        $this->threadId = $threadId;
    }

    public function handle()
    {
        $context = $this->guards();

        if (!$context) {
            return;
        }

        ['thread' => $thread, 'conversation' => $conversation, 'latestInbound' => $latestInbound, 'account' => $account] = $context;

        // Kapso's Meta proxy wants bare international digits. contact_phone
        // is written verbatim from the module's own PhoneNumber::toE164()
        // ("+" followed by digits) by ProcessInboundMessage, so a leading
        // "+" is stripped defensively here rather than assumed absent.
        $to     = preg_replace('/\D+/', '', (string) $latestInbound->contact_phone);
        $client = new KapsoClient($account);

        foreach ($this->parts($thread) as $part) {
            try {
                $this->claimAndSend($client, $account, $conversation, $thread, $part, $to);
            } catch (KapsoApiException $e) {
                if ($this->attempts() < $this->tries) {
                    // Not the final attempt: rethrow so Laravel's queue
                    // retries the whole job. Parts already accepted above
                    // are skipped on the re-run via their recorded wamid;
                    // the part that just failed is left `sending` for the
                    // retry to pick back up.
                    throw $e;
                }

                $this->finalizeFailure($thread, $conversation, $e);

                return;
            }
        }

        $this->markReadBestEffort($client, $latestInbound);
    }

    /**
     * Guards, in the order the design demands: thread exists; it is still a
     * published message (not undone back to draft, not some other thread
     * type); its conversation exists and is on the WhatsApp channel; and an
     * active KapsoAccount can be found via the conversation's latest inbound
     * message (the account that actually received it, not merely "some
     * account configured for this mailbox"). Any failure here is logged at
     * info level and the job quietly does nothing -- these are races
     * against the undo window and against admin account changes, not bugs.
     */
    protected function guards()
    {
        $thread = Thread::find($this->threadId);

        if (!$thread) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: thread not found, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        if ((int) $thread->type !== Thread::TYPE_MESSAGE || (int) $thread->state !== Thread::STATE_PUBLISHED) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: thread is no longer a published message (undone?), skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $conversation = Conversation::find($thread->conversation_id);

        if (!$conversation || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: conversation missing or not a WhatsApp conversation, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $latestInbound = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->first();

        if (!$latestInbound) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: no inbound message to reply to, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        $account = KapsoAccount::find($latestInbound->account_id);

        if (!$account || !$account->is_active) {
            \Log::info('[KapsoWhatsApp] SendReplyMessage: account missing or inactive, skipping', ['thread_id' => $this->threadId]);

            return null;
        }

        return compact('thread', 'conversation', 'latestInbound', 'account');
    }

    /**
     * The list of parts this reply breaks down into: zero or more text
     * chunks (skipped entirely for an empty body -- e.g. an attachment-only
     * reply), then one part per attachment, in thread order.
     */
    protected function parts(Thread $thread)
    {
        $parts = [];

        $text = trim(\Helper::htmlToText($thread->body));

        if ($text !== '') {
            foreach ($this->chunkText($text) as $i => $chunk) {
                $parts[] = [
                    'part_key'      => KapsoMessage::partKeyForBodyChunk($i),
                    'attachment_id' => null,
                    'type'          => 'text',
                    'body'          => $chunk,
                ];
            }
        }

        foreach ($thread->attachments as $attachment) {
            $isImage = str_starts_with((string) $attachment->mime_type, 'image/');

            $parts[] = [
                'part_key'      => KapsoMessage::partKeyForAttachment($attachment->id),
                'attachment_id' => $attachment->id,
                'type'          => $isImage ? 'image' : 'document',
                'link'          => $this->attachmentLink($attachment),
                'filename'      => $attachment->file_name,
            ];
        }

        return $parts;
    }

    protected function chunkText($text)
    {
        return mb_str_split($text, self::TEXT_CHUNK_LIMIT);
    }

    /**
     * Attachment::url() enforces the download token (HMAC-SHA256, checked
     * with hash_equals in OpenController::download()) but returns a
     * root-relative path -- absolute-prefixing it here is the only change
     * made to it, so the token and its query string reach Kapso unaltered.
     */
    protected function attachmentLink(Attachment $attachment)
    {
        $url = $attachment->url();

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim(config('app.url'), '/').$url;
        }

        return $url;
    }

    protected function buildPayload($to, array $part)
    {
        if ($part['type'] === 'text') {
            return [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $part['body']],
            ];
        }

        if ($part['type'] === 'image') {
            return [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'image',
                'image'             => ['link' => $part['link']],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'document',
            'document'          => ['link' => $part['link'], 'filename' => $part['filename']],
        ];
    }

    /**
     * Claims one part's row (inserting it, or re-claiming a sending/failed
     * leftover from an earlier attempt of this same reply) and sends it.
     * Skips outright -- no HTTP call at all -- when the part is already
     * accepted: this is what makes a retried or re-run job safe.
     *
     * The duplicate-key catch mirrors the module's existing convention
     * (ProcessInboundMessage, ReconcileOutboundMessage): catch broadly, then
     * re-check by querying; only treat it as "someone else claimed this
     * part" if that query actually finds a row, otherwise rethrow -- so an
     * unrelated DB failure is never silently swallowed as a claim race.
     */
    protected function claimAndSend(KapsoClient $client, KapsoAccount $account, Conversation $conversation, Thread $thread, array $part, $to)
    {
        $row = new KapsoMessage();
        $row->account_id      = $account->id;
        $row->conversation_id = $conversation->id;
        $row->thread_id       = $thread->id;
        $row->part_key        = $part['part_key'];
        $row->direction       = KapsoMessage::DIRECTION_OUTBOUND;
        $row->send_state      = KapsoMessage::SEND_STATE_SENDING;
        $row->contact_phone   = $to;

        if ($part['attachment_id']) {
            $row->attachment_id = $part['attachment_id'];
        }

        try {
            $row->save();
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = KapsoMessage::where('thread_id', $thread->id)->where('part_key', $part['part_key'])->first();

            if (!$existing) {
                throw $e;
            }

            if ($existing->wamid || $existing->send_state === KapsoMessage::SEND_STATE_ACCEPTED) {
                // Already sent, by this job's own earlier attempt or a
                // previous run entirely -- nothing left to do for this part.
                return;
            }

            // A `sending`/`failed` leftover from a previous attempt of this
            // same reply: re-claim it and try again.
            $row = $existing;
            $row->send_state = KapsoMessage::SEND_STATE_SENDING;
            $row->error       = null;
            $row->save();
        }

        $response = $client->sendWhatsAppMessage($this->buildPayload($to, $part));

        $row->wamid      = KapsoClient::extractWamid($response);
        $row->send_state = KapsoMessage::SEND_STATE_ACCEPTED;
        $row->save();
    }

    /**
     * The final attempt has failed: every part this job has claimed for
     * this thread that never made it to `accepted` (the one that just threw,
     * plus any earlier `sending`/`failed` leftover it did not get back to)
     * is marked failed with the same error, and exactly one line item is
     * posted -- not one per part, since the agent needs one visible failure
     * per reply, not one per chunk/attachment. Parts never even claimed
     * (later in the list, never reached because the loop stopped here) are
     * left with no row at all; a genuinely new send attempt is what would
     * create them, not this cleanup.
     */
    protected function finalizeFailure(Thread $thread, Conversation $conversation, KapsoApiException $e)
    {
        KapsoMessage::where('thread_id', $thread->id)
            ->whereNotNull('part_key')
            ->where('send_state', '<>', KapsoMessage::SEND_STATE_ACCEPTED)
            ->update(['send_state' => KapsoMessage::SEND_STATE_FAILED, 'error' => $e->getMessage()]);

        DeliveryFailureLineItem::create($conversation, $e->getMessage());
    }

    /**
     * Blue ticks for the customer -- best-effort only. A failure here must
     * never fail the job: the reply itself was already accepted by Kapso: it
     * has been sent either way, whether or not the read receipt for the
     * customer's own last message went through.
     */
    protected function markReadBestEffort(KapsoClient $client, KapsoMessage $latestInbound)
    {
        if (!$latestInbound->wamid) {
            return;
        }

        try {
            $client->markMessageRead($latestInbound->wamid);
        } catch (\Throwable $e) {
            \Log::warning('[KapsoWhatsApp] SendReplyMessage: mark-read failed', [
                'wamid' => $latestInbound->wamid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
