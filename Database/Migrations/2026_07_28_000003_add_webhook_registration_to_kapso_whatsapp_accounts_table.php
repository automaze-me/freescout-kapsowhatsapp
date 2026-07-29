<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebhookRegistrationToKapsoWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            // Kapso's webhook uuid. Present only once this module has
            // registered (or adopted) the webhook itself.
            $table->string('webhook_id', 64)->nullable()->after('webhook_secret');
            // The URL registered with Kapso, so a later APP_URL/domain change
            // is visible as "registered elsewhere" instead of silently
            // delivering nowhere.
            $table->string('webhook_url', 255)->nullable()->after('webhook_id');
            // Tri-state on purpose: null = never asked Kapso, true = active,
            // false = Kapso has paused it. A default of false would render as
            // "paused" for every pre-existing row.
            $table->boolean('webhook_active')->nullable()->after('webhook_url');
            $table->timestamp('webhook_checked_at')->nullable()->after('webhook_active');
            $table->text('webhook_error')->nullable()->after('webhook_checked_at');
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_id', 'webhook_url', 'webhook_active', 'webhook_checked_at', 'webhook_error',
            ]);
        });
    }
}
