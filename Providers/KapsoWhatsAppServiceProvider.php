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
    }
}
