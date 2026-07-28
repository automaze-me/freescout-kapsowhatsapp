<?php

namespace Modules\KapsoWhatsApp\Providers;

use Illuminate\Support\ServiceProvider;

class KapsoWhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Community channel code. 100 and 101 are claimed by the MetaWhatsApp module.
     */
    const CHANNEL = 102;
    const CHANNEL_NAME = 'WhatsApp';

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
            $channels[self::CHANNEL] = self::CHANNEL_NAME;

            return $channels;
        }, 20, 1);

        // Channel code -> display name (used by CustomerChannel::getChannelName()).
        \Eventy::addFilter('channel.name', function ($name, $channel = null) {
            if ((int) $channel === self::CHANNEL) {
                return self::CHANNEL_NAME;
            }

            return $name;
        }, 20, 2);
    }
}
