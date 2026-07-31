<?php

namespace Modules\KapsoWhatsApp\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
use App\Thread;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Jobs\SendTemplateMessage;
use Modules\KapsoWhatsApp\Services\KapsoClient;

/**
 * Stage 3c: the two JSON endpoints behind the closed-window notice's "Send a
 * template…" picker -- list the project's eligible templates, and send one.
 * A separate controller from KapsoWhatsAppController on purpose: that one is
 * the admin-only account/webhook management surface (every action gated by
 * its own authorizeAdmin()), and these two are the opposite -- any agent who can
 * reply to the conversation must be able to use them, never admin-only. See
 * resolveConversation() for the exact check this borrows from core.
 */
class TemplatesController extends Controller
{
    /**
     * The conversation-level authorisation the reply UI itself uses for an
     * existing conversation: core's own `send_reply` ajax action performs
     * exactly this check before letting a reply through
     * (app/Http/Controllers/ConversationsController.php:706,
     * `$user->can('view', $conversation)`, delegating to
     * ConversationPolicy::view() -- admins pass unconditionally, everyone
     * else needs mailbox access and, if the mailbox restricts to assigned
     * conversations, to be the assignee/creator). Deliberately NOT
     * KapsoWhatsAppController::authorizeAdmin(): sending a template is part
     * of replying, not account administration, so the same population that
     * may reply at all must be able to do this too.
     *
     * 404, not 403, for a conversation that either does not exist or is not
     * on the WhatsApp channel -- there is nothing template-shaped about it
     * to authorise access to in the first place, and 404 does not leak
     * whether a non-WhatsApp conversation id exists to a caller who is
     * merely probing this WhatsApp-specific endpoint.
     */
    protected function resolveConversation($conversationId): Conversation
    {
        $conversation = Conversation::find($conversationId);

        if (!$conversation || (int) $conversation->channel !== KapsoAccount::CHANNEL) {
            abort(404);
        }

        if (!auth()->user() || !auth()->user()->can('view', $conversation)) {
            abort(403);
        }

        return $conversation;
    }

    /**
     * The account (and the inbound row it was derived from) this
     * conversation's WhatsApp identity resolves to -- the same "latest
     * inbound row for this conversation, then the account that received it"
     * derivation SendReplyMessage::guards() and SendTemplateMessage::guards()
     * both use, copied here rather than shared: this module's own
     * convention (see those two classes, which do not share it with each
     * other either) is that each caller owns this small a query rather than
     * factoring out a one-line helper across unrelated classes.
     *
     * Returns [null, null] when the conversation has no inbound WhatsApp
     * message yet, or when the account it points at is missing/inactive --
     * Stage 3c is closed-window replies only (see the spec's "Out of
     * scope"), so in practice a channel-102 conversation reaching here
     * always has one, but a hand-crafted or edge-case request must still
     * degrade to an honest error rather than a fatal.
     *
     * @return array{0: ?KapsoAccount, 1: ?KapsoMessage}
     */
    protected function resolveAccount(Conversation $conversation): array
    {
        $latestInbound = KapsoMessage::where('conversation_id', $conversation->id)
            ->where('direction', KapsoMessage::DIRECTION_INBOUND)
            ->whereNotNull('contact_phone')
            ->orderByDesc('id')
            ->first();

        if (!$latestInbound) {
            return [null, null];
        }

        $account = KapsoAccount::find($latestInbound->account_id);

        if (!$account || !$account->is_active) {
            return [null, null];
        }

        return [$account, $latestInbound];
    }

    /**
     * GET kapso-whatsapp/templates/{conversation_id} -> {templates: [...]}
     * or {error: <translated>}. Always HTTP 200 on a Kapso-side failure
     * (auth/ownership failures still 403/404 via resolveConversation()) --
     * the picker shows the error key inline, never a stack trace, and a
     * fresh fetch every time the picker opens is the point: no DB cache,
     * Kapso is the source of truth for which templates are approved right
     * now.
     */
    public function list($conversationId)
    {
        $conversation = $this->resolveConversation($conversationId);

        [$account] = $this->resolveAccount($conversation);

        if (!$account) {
            return response()->json(['error' => $this->noAccountMessage()]);
        }

        try {
            $templates = (new KapsoClient($account))->listMessageTemplates();
        } catch (KapsoApiException $e) {
            return response()->json(['error' => $e->getMessage()]);
        }

        return response()->json(['templates' => $templates]);
    }

    /**
     * POST kapso-whatsapp/templates/{conversation_id} with
     * {name, language, variables: []} -> {thread_id} on success, 422 with a
     * translated {error} on any validation failure. Validates against a
     * FRESH template list (never trusts the client's copy of what the
     * picker showed) so a template that was approved a minute ago but is no
     * longer eligible cannot be sent.
     *
     * On success: creates the thread synchronously, with the direct `new
     * Thread` idiom ReconcileOutboundMessage::recordForeignSend() uses for
     * an agent-side message -- source_via PERSON_USER, TYPE_MESSAGE,
     * STATE_PUBLISHED -- but attributed to the ACTING agent
     * (created_by_user_id/user_id), not the synthetic SystemUser
     * recordForeignSend() uses for messages nobody in FreeScout actually
     * sent. This is provably not the `chat_conversation.send_reply` path
     * (that only ever fires from core's own reply-save flow via an
     * explicit event this method never raises), so it can never be
     * double-sent by that listener -- see the spec's "Delivery mechanics"
     * for Stage 3c. Then mirrors the applicable half of
     * recordForeignSend()'s conversation update (last_reply_at,
     * last_reply_from, setPreview() with the plain substituted text, not
     * the escaped HTML) and dispatches SendTemplateMessage to do the actual
     * Kapso call.
     *
     * Does NOT re-check window state: templates are legal to send at any
     * time (that is the entire point of this feature), so re-checking here
     * would only add a clock-race false block -- see the spec's "Endpoints
     * & UI transport".
     */
    public function send(Request $request, $conversationId)
    {
        $conversation = $this->resolveConversation($conversationId);

        [$account] = $this->resolveAccount($conversation);

        if (!$account) {
            return response()->json(['error' => $this->noAccountMessage()], 422);
        }

        $name     = trim((string) $request->input('name'));
        $language = trim((string) $request->input('language'));
        $rawVariables = $request->input('variables', []);
        $variables    = is_array($rawVariables) ? array_values($rawVariables) : [];

        if ($name === '' || $language === '') {
            return response()->json(['error' => __('Choose a template to send.')], 422);
        }

        try {
            $templates = (new KapsoClient($account))->listMessageTemplates();
        } catch (KapsoApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $template = $this->findTemplate($templates, $name, $language);

        if (!$template) {
            return response()->json(['error' => __('That template is no longer available. Reload the conversation and try again.')], 422);
        }

        $clean = $this->validateVariables($variables, $template['variables']);

        if ($clean === null) {
            return response()->json(['error' => __('Fill in every value for this template (each up to 1024 characters).')], 422);
        }

        $substituted = $this->substitute($template['body'], $clean);

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = auth()->id();
        $thread->created_by_user_id = auth()->id();
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = nl2br(e($substituted));
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $conversation->customer_id;
        $thread->save();

        // Mirrors ReconcileOutboundMessage::recordForeignSend()'s
        // conversation update -- see that method's comment for why this is
        // written again here even though ThreadObserver::created() already
        // touched the same three fields off the thread's own body/timestamp:
        // this overwrite gives a clean plain-text preview (not the escaped
        // HTML the observer would have used) and "now", not the thread's
        // created_at.
        $conversation->last_reply_at   = now();
        $conversation->last_reply_from = Conversation::PERSON_USER;
        $conversation->setPreview($substituted);
        $conversation->save();

        SendTemplateMessage::dispatch($thread->id, $template['name'], $template['language'], $clean);

        return response()->json(['thread_id' => $thread->id]);
    }

    protected function noAccountMessage()
    {
        return __('This conversation has no active WhatsApp account to send from.');
    }

    protected function findTemplate(array $templates, $name, $language)
    {
        foreach ($templates as $template) {
            if ($template['name'] === $name && $template['language'] === $language) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Every value must be present (count must exactly match the template's
     * declared variable count), non-blank after trim, and at most 1024
     * characters (Meta's own per-parameter limit, the same cap
     * SendTemplateMessage::VARIABLE_CHAR_LIMIT defensively re-enforces).
     * Returns the trimmed values, or null on any violation -- the caller
     * turns null into one generic 422, since this is a small inline form,
     * not a multi-field admin form that benefits from per-field messages.
     *
     * @return ?string[]
     */
    protected function validateVariables(array $variables, int $expectedCount): ?array
    {
        if (count($variables) !== $expectedCount) {
            return null;
        }

        $clean = [];

        foreach ($variables as $variable) {
            $value = trim((string) $variable);

            if ($value === '' || mb_strlen($value) > 1024) {
                return null;
            }

            $clean[] = $value;
        }

        return $clean;
    }

    /**
     * Substitutes each `{{n}}` placeholder with $variables[n-1]. Eligibility
     * already guarantees (KapsoClient::listMessageTemplates()) that every
     * placeholder in an offered template's body is positional and that
     * $variables has exactly as many entries as the template declares, so
     * every placeholder resolves; the `?? ''` is defence in depth only, not
     * a case this method expects to hit.
     */
    protected function substitute($body, array $variables)
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', function ($matches) use ($variables) {
            $index = ((int) $matches[1]) - 1;

            return $variables[$index] ?? '';
        }, $body);
    }
}
