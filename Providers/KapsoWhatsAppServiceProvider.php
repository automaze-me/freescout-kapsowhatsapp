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
    }
}
