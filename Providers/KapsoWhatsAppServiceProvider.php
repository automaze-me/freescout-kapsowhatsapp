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
        \Eventy::addAction('thread.meta', function ($thread) {
            if ($thread->getMeta(\Modules\KapsoWhatsApp\Entities\KapsoMessage::THREAD_META_SENT_AT)) {
                echo '<div class="thread-meta"><i class="glyphicon glyphicon-ok"></i> '.e(__('Sent via WhatsApp')).'</div>';
            }
        }, 20, 1);

        // Stage 3b: the 24h customer-service window banner, above the
        // reply/note editor. Core fires this action as
        // @action('conversation.after_subject_block', $conversation,
        // $mailbox) (resources/views/conversations/view.blade.php:231), in
        // both normal and chat-mode rendering. WindowState returns null for
        // non-WhatsApp conversations and for a WhatsApp conversation that
        // has never had an inbound message, in which case nothing is
        // rendered -- other modules (e.g. Checklists) already use this same
        // hook, so this must stay silent rather than assume it owns the
        // whole block.
        \Eventy::addAction('conversation.after_subject_block', function ($conversation, $mailbox) {
            $state = \Modules\KapsoWhatsApp\Services\WindowState::forConversation($conversation);

            if (!$state) {
                return;
            }

            echo view('kapsowhatsapp::partials/window_banner', ['state' => $state])->render();
        }, 20, 2);

        // Ships the module's own JS asset -- on a closed window it disables
        // the reply triggers client-side (Public/js/kapsowhatsapp.js). See
        // "Blocking the editor" in the Stage 3b spec section.
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(KAPSO_WHATSAPP_MODULE).'/js/kapsowhatsapp.js';

            return $javascripts;
        }, 20, 1);
    }
}
