<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebhookCheckAttemptedAtToKapsoWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            // Stamped on every refresh/resume/register attempt, successful or
            // not -- unlike webhook_checked_at, which only moves when we
            // genuinely learned the current state. This is what
            // refreshStaleWebhookStatus() gates the outbound call on, so a
            // Kapso that is failing (not just absent) still gets throttled to
            // one call per account per STALE_AFTER_MINUTES instead of one per
            // settings-page load.
            $table->timestamp('webhook_check_attempted_at')->nullable()->after('webhook_checked_at');
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('webhook_check_attempted_at');
        });
    }
}
