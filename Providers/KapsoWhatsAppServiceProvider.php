<?php

namespace Modules\KapsoWhatsApp\Providers;

use Illuminate\Support\ServiceProvider;

class KapsoWhatsAppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'kapsowhatsapp');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'kapsowhatsapp');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'kapsowhatsapp');
    }

    protected function hooks()
    {
        // Make WhatsApp selectable as a communication channel.
        \Eventy::addFilter('channels.list', function ($channels) {
            $channels[\Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL]
                = \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL_NAME;

            return $channels;
        }, 20, 1);

        // Channel code -> display name (used by CustomerChannel::getChannelName()).
        \Eventy::addFilter('channel.name', function ($name, $channel = null) {
            if ((int) $channel === \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL) {
                return \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL_NAME;
            }

            return $name;
        }, 20, 2);

        \Eventy::addAction('menu.manage.append', function () {
            if (auth()->user() && auth()->user()->isAdmin()) {
                echo view('kapsowhatsapp::menu')->render();
            }
        });

        // Chat-type conversations (WhatsApp among them) never get emailed a
        // reply -- core fires this action instead, once per undo-window
        // delay, for every chat conversation regardless of which channel
        // module owns it (app/Listeners/SendReplyToCustomer.php). The
        // listener itself is what filters down to only WhatsApp
        // (KapsoAccount::CHANNEL) conversations and queues delivery.
        \Eventy::addAction('chat_conversation.send_reply', [new \Modules\KapsoWhatsApp\Listeners\SendReplyToWhatsApp(), 'handle'], 20, 3);

        // Stage 4: per-reply channel selection. capture() writes the
        // agent's posted channel choice onto the reply thread's meta;
        // intercept() reads it back inside core's own send-or-skip decision
        // and, only for a genuine cross-channel choice, dispatches the
        // OTHER channel's send job itself. See
        // Listeners/RouteReplyChannel.php's class docblock and "Stage 4:
        // per-reply channel selection" in the design spec.
        \Eventy::addAction('thread.before_save_from_request', [\Modules\KapsoWhatsApp\Listeners\RouteReplyChannel::class, 'capture'], 20, 2);
        \Eventy::addFilter('conversation.skip_send_reply_to_customer', [\Modules\KapsoWhatsApp\Listeners\RouteReplyChannel::class, 'intercept'], 20, 3);

        // Core already renders a name and download link for every
        // attachment (resources/views/conversations/partials/thread_attachments.blade.php);
        // this adds an inline thumbnail for images only. Other attachment
        // types keep core's default row untouched.
        \Eventy::addAction('thread.attachment_append', function ($attachment, $thread, $conversation, $mailbox) {
            if ((int) $attachment->type !== \App\Attachment::TYPE_IMAGE) {
                return;
            }

            echo '<div class="kapsowhatsapp-attachment-preview">'
                .'<a href="'.e($attachment->url()).'" target="_blank">'
                .'<img src="'.e($attachment->url()).'" alt="'.e($attachment->file_name).'" '
                .'style="max-width:200px;max-height:200px;border-radius:4px;margin-top:6px;">'
                .'</a></div>';
        }, 20, 4);

        // Services\DeliveryFailureLineItem::create() posts a
        // TYPE_LINEITEM thread for a WhatsApp delivery failure with
        // action_type left NULL (core has no ACTION_TYPE_* for this and no
        // hook to register one). Thread::getActionText() has no fallback
        // branch for a NULL action_type -- it falls through to this filter
        // with an empty string -- and thread.blade.php's TYPE_LINEITEM
        // branch only ever renders getActionText(), never $thread->body. Left
        // unhandled, the line item would render as an empty grey bar with a
        // timestamp and no visible text: the agent sees nothing, exactly the
        // failure mode this module exists to prevent. The meta flag (rather
        // than e.g. matching on action_type/status) is what lets this filter
        // recognise "this is our own line item" without guessing at other
        // line items core or other modules may create with a NULL
        // action_type for unrelated reasons.
        \Eventy::addFilter('thread.action_text', function ($didThis, $thread, $conversationNumber, $escape, $viewedByUser) {
            if ((int) $thread->type === \App\Thread::TYPE_LINEITEM
                && $thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::LINEITEM_META_DELIVERY_FAILED)) {
                return $thread->body;
            }

            return $didThis;
        }, 20, 5);

        // ReconcileOutboundMessage::markOwnSendSent() sets this meta on a
        // reply thread once Kapso's `whatsapp.message.sent` webhook confirms
        // our own accepted send actually went out. Core fires this action as
        // @action('thread.meta', $thread, $loop, $threads, $conversation,
        // $mailbox) (resources/views/conversations/partials/thread.blade.php)
        // -- five args, of which only $thread is needed here.
        // The per-message channel chip next to the sender's name -- see
        // Services/ThreadChannelBadge.php. Core fires this hook inside
        // .thread-person, right after the name (thread.blade.php:102).
        \Eventy::addAction('thread.after_person_action', [\Modules\KapsoWhatsApp\Services\ThreadChannelBadge::class, 'render'], 20, 5);

        \Eventy::addAction('thread.meta', function ($thread) {
            // One status line, highest state wins -- like WhatsApp's own
            // ticks: read (blue double tick) > delivered (double tick) >
            // sent (single tick). The receipt metas are best-effort
            // presence signals stamped by ProcessDeliveryReceipt; the sent
            // marker keeps its own delivered-and-healthy contract and is
            // merely superseded VISUALLY by a higher tick.
            $read      = $thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::THREAD_META_READ_AT);
            $delivered = $thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::THREAD_META_DELIVERED_AT);
            $ticks     = '<i class="glyphicon glyphicon-ok"></i><i class="glyphicon glyphicon-ok kwa-tick-2"></i> ';

            if (is_string($read) && $read !== '') {
                echo '<div class="thread-meta kwa-ticks kwa-ticks-read">'.$ticks.e(__('Seen by customer')).self::receiptTime($read).'</div>';
            } elseif (is_string($delivered) && $delivered !== '') {
                echo '<div class="thread-meta kwa-ticks">'.$ticks.e(__('Delivered via WhatsApp')).self::receiptTime($delivered).'</div>';
            } elseif ($thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::THREAD_META_SENT_AT)) {
                echo '<div class="thread-meta"><i class="glyphicon glyphicon-ok"></i> '.e(__('Sent via WhatsApp')).'</div>';
            }

            // The customer's reaction to this message, as a remark under it
            // (user feedback: in the body it read as part of the message).
            $reaction = $thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::THREAD_META_REACTION);

            if (is_string($reaction) && $reaction !== '') {
                echo '<div class="thread-meta kwa-thread-reaction">'.e($reaction).' '.e(__('Customer reaction')).'</div>';
            }
        }, 20, 1);

        // Stage 3b: the 24h customer-service window banner, above the
        // reply/note editor. Placement depends on chat vs. normal mode: in
        // chat mode, core collapses conversation.after_subject_block's
        // output inside the #conv-top-blocks accordion
        // (view.blade.php:229-234), which is not shown by default --
        // rendering the banner there would be effectively invisible.
        // conversation.after_subject (view.blade.php:207), by contrast,
        // renders above that collapse in every mode, so chat mode renders
        // there instead; normal mode keeps the original after_subject_block
        // placement. Each hook checks $conversation->isInChatMode() itself
        // and renders only on its own side, so exactly one echoes per
        // request -- never both. renderWindowBanner() itself now renders
        // nothing for a non-channel-102 conversation (Stage 4: WindowState
        // generalised its own gate to "has WhatsApp history", so this
        // provider carries the channel-102-only restriction explicitly --
        // banners stay native-WhatsApp-only per spec; the Task 3 picker is
        // the window surface for a mixed conversation) or for a
        // channel-102 conversation that has never had an inbound message --
        // other modules (e.g. Checklists, CustomFields, Tags) already use
        // these same two hooks, so this must stay silent rather than assume
        // it owns the whole block.
        \Eventy::addAction('conversation.after_subject', function ($conversation, $mailbox) {
            if ($conversation->isInChatMode()) {
                // inline: this placement sits in the subject row, directly
                // after the floated Chat Mode / channel-pill tags -- the
                // partial aligns the open-state hint with them.
                self::renderWindowBanner($conversation, true);
            }
        }, 20, 2);

        \Eventy::addAction('conversation.after_subject_block', function ($conversation, $mailbox) {
            if (!$conversation->isInChatMode()) {
                self::renderWindowBanner($conversation, false);
            }
        }, 20, 2);

        // C1: the Reply button must not exist at all when the window is
        // closed AND the customer has no email on file -- with no
        // `.conv-reply` in the DOM, the toolbar shows no dead button and
        // chat mode's own auto-open (public/js/main.js:1354
        // `$(".conv-reply").click()`) simply no-ops. This is core's own
        // permission filter for the button
        // (app/Misc/ConversationActionButtons.php:25); the module JS below
        // becomes belt-and-braces for whatever this filter cannot reach
        // (e.g. an already-rendered page).
        //
        // Stage 4 revision (forced by per-reply channel selection -- see
        // the spec's UI section, "3b revision"): a closed WhatsApp window
        // no longer removes the button unconditionally. With an email on
        // file, the reply box stays usable -- the Task 3 picker below locks
        // its default to email and merely shows WhatsApp disabled, exactly
        // like any other mixed conversation. Only when NEITHER channel can
        // carry the reply (closed window, no email) is there truly nothing
        // to click Reply for.
        \Eventy::addFilter('conversation.reply_button.enabled', function ($enabled, $conversation) {
            $state = \Modules\KapsoWhatsApp\Services\WindowState::forConversation($conversation);

            if ($state && !$state['open'] && !\Modules\KapsoWhatsApp\Services\ChannelChoice::emailAvailable($conversation)) {
                return false;
            }

            return $enabled;
        }, 20, 2);

        // Stage 4, Task 3: the per-reply channel picker, injected at core's
        // reply_form.after hook (view.blade.php:342, inside .conv-reply-block
        // but -- see renderChannelPicker()'s and the partial's own docblocks
        // for the empirical finding -- OUTSIDE the reply <form>). Renders
        // nothing unless ChannelChoice::pickerAvailable() says the agent
        // genuinely has a choice to make on this conversation.
        \Eventy::addAction('reply_form.after', function ($conversation) {
            self::renderChannelPicker($conversation);
        }, 20, 1);

        // Ships the module's own JS asset -- on a closed window it disables
        // the reply triggers client-side, and in both window states it
        // colours the channel pill (Public/js/kapsowhatsapp.js). See
        // "Blocking the editor" in the Stage 3b spec section.
        // NO ?v= cache-buster on these paths, ever: core feeds this list to
        // Minify, which stats every entry on disk -- a query string makes
        // the "file" unfindable, Minify throws, and core swallows the
        // exception in a way that takes the ENTIRE app JS/CSS bundle down
        // (verified live: jQuery itself stopped loading). Minify's own
        // build hash handles cache-busting where bundling is on; where
        // assets are served individually, browsers revalidate via
        // ETag/Last-Modified heuristics.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(KAPSO_WHATSAPP_MODULE).'/js/kapsowhatsapp.js';

            return $javascripts;
        }, 20, 1);

        // The pill-colour classes the JS above applies
        // (Public/css/kapsowhatsapp.css).
        \Eventy::addFilter('stylesheets', function ($stylesheets) {
            $stylesheets[] = \Module::getPublicPath(KAPSO_WHATSAPP_MODULE).'/css/kapsowhatsapp.css';

            return $stylesheets;
        }, 20, 1);
    }

    /**
     * Shared by both the after_subject (chat mode) and after_subject_block
     * (normal mode) hooks above -- identical rendering, different gate.
     * $inline tells the partial whether it is rendering inside the subject
     * row (chat mode) and must align the open-state hint with the floated
     * pills there, or standing in its own row (normal mode).
     */
    protected static function renderWindowBanner($conversation, $inline = false)
    {
        // Stage 4: WindowState no longer gates on channel itself (it
        // answers for any conversation with WhatsApp history), so this
        // explicit check is what keeps the banner channel-102-only, per
        // spec -- a mixed (non-102) conversation's window state surfaces
        // through the Task 3 picker instead, never through this banner.
        if ((int) $conversation->channel !== \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL) {
            return;
        }

        $state = \Modules\KapsoWhatsApp\Services\WindowState::forConversation($conversation);

        if (!$state) {
            return;
        }

        // F1 (whole-stage review, CRITICAL): the closed banner used to be
        // the ONLY source of the marker Public/js/kapsowhatsapp.js keyed its
        // reply-blocking on (data-kwa-window-closed), which meant EVERY
        // closed 102 conversation got its Reply button blocked client-side
        // -- including one where C1 above correctly leaves the button in
        // the DOM because the customer has an email on file. The picker
        // (Task 3) renders INSIDE .conv-reply-block, so that stale JS
        // blocker made it unreachable too: a dead Reply button on a
        // conversation that could still legitimately send email.
        // $blockReply is the single authority the JS now re-keys on
        // (data-kwa-block-reply, emitted below only when this is true) --
        // computed with exactly the same predicate as C1's filter, so the
        // two can never disagree: nothing can send only when the window is
        // closed AND there is no email to fall back to.
        $blockReply = !$state['open'] && !\Modules\KapsoWhatsApp\Services\ChannelChoice::emailAvailable($conversation);

        // $conversation is passed through so the closed branch can build its
        // "Send a template…" picker URLs (Stage 3c) via route(...).
        echo view('kapsowhatsapp::partials/window_banner', ['state' => $state, 'inline' => $inline, 'conversation' => $conversation, 'blockReply' => $blockReply])->render();
    }

    /**
     * " · 5 minutes ago" for a receipt timestamp, '' when it does not
     * parse -- the remark is better without a time than absent, and meta
     * is stored data that a future format change must not turn into a
     * render-time fatal.
     */
    protected static function receiptTime($iso)
    {
        try {
            return ' · '.e(\App\User::dateDiffForHumans(\Carbon\Carbon::parse($iso)));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Stage 4, Task 3: the per-reply channel picker
     * (Resources/views/partials/channel_picker.blade.php), rendered at core's
     * reply_form.after hook. Renders nothing unless
     * ChannelChoice::pickerAvailable($conversation) -- both channels must be
     * genuinely reachable, or there is nothing to pick between (this is
     * conversation-scoped, never customer-scoped -- see
     * Services/ChannelChoice.php's class docblock) -- EXCEPT for the
     * template-only fallback below (whole-stage review, F5).
     */
    protected static function renderChannelPicker($conversation)
    {
        if (!\Modules\KapsoWhatsApp\Services\ChannelChoice::pickerAvailable($conversation)) {
            self::renderTemplateOnlyPicker($conversation);

            return;
        }

        $state      = \Modules\KapsoWhatsApp\Services\WindowState::forConversation($conversation);
        $windowOpen = $state ? $state['open'] : false;

        // Unreachable today: pickerAvailable() already required
        // ChannelChoice::whatsappAvailable($conversation) -- at least one
        // inbound row for THIS conversation -- which is exactly
        // WindowState::compute()'s own anchor query, so $state can never
        // actually be null here. Guarded anyway, the same defensive style
        // WindowState::compute() itself uses for its own "impossible"
        // branch (see that method's comment).
        $windowHint = '';
        if ($state) {
            $windowHint = $windowOpen
                ? __('closes :relative', ['relative' => \App\User::dateDiffForHumans($state['closes_at']->copy())])
                : __('window closed :relative', ['relative' => \App\User::dateDiffForHumans($state['closes_at']->copy())]);
        }

        // The 3c template control lives on the picker only for a NON-102
        // conversation -- a channel-102 conversation already carries it on
        // the closed banner above (renderWindowBanner()); never both, per
        // spec.
        $templateTransport = !$windowOpen && (int) $conversation->channel !== \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL;

        echo view('kapsowhatsapp::partials/channel_picker', [
            'conversation'      => $conversation,
            'default'           => \Modules\KapsoWhatsApp\Services\ChannelChoice::defaultChannel($conversation),
            'windowOpen'        => $windowOpen,
            'windowHint'        => $windowHint,
            'templateTransport' => $templateTransport,
            'templateOnly'      => false,
        ])->render();
    }

    /**
     * F5 (whole-stage review, MINOR): a closed, non-102 conversation with
     * WhatsApp history but no customer email is otherwise a dead end --
     * pickerAvailable() (above) refuses it because there is no email leg,
     * renderWindowBanner() refuses it because it is not channel-102, and
     * the revised C1 conversation.reply_button.enabled filter correctly
     * removes the Reply button because nothing free-form can send -- yet
     * TemplatesController's endpoints would still happily serve this
     * conversation (its guard is "has WhatsApp history", which this
     * conversation satisfies). Render the picker partial in template-only
     * mode instead: the 3c transport attributes, the window-closed hint,
     * and the "Send a template…" button, but no radios -- there is nothing
     * left to pick between. Channel-102 conversations never reach here for
     * that reason: their closed banner (renderWindowBanner()) already
     * carries the button, and pickerAvailable() only ever fails them on the
     * missing-email leg -- same shape, but the banner already covers it.
     */
    protected static function renderTemplateOnlyPicker($conversation)
    {
        if (!\Modules\KapsoWhatsApp\Services\ChannelChoice::whatsappAvailable($conversation)) {
            return;
        }

        if ((int) $conversation->channel === \Modules\KapsoWhatsApp\Entities\KapsoAccount::CHANNEL) {
            return;
        }

        $state = \Modules\KapsoWhatsApp\Services\WindowState::forConversation($conversation);

        if (!$state || $state['open']) {
            return;
        }

        $windowHint = __('window closed :relative', ['relative' => \App\User::dateDiffForHumans($state['closes_at']->copy())]);

        echo view('kapsowhatsapp::partials/channel_picker', [
            'conversation'      => $conversation,
            'default'           => null,
            'windowOpen'        => false,
            'windowHint'        => $windowHint,
            'templateTransport' => true,
            'templateOnly'      => true,
        ])->render();
    }
}
