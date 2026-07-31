<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports WindowState::forConversation()'s per-contact lookup: for a given
 * (account_id, contact_phone) pair, find the most recent *inbound* row.
 * `direction` is part of the index (not just account_id/contact_phone/
 * created_at) because the lookup query always filters on
 * `direction = 'inbound'` as well
 * (Services/WindowState.php::compute()) -- without it in the index, MySQL
 * can use the index to narrow down to the (account, contact) pair and its
 * created_at ordering, but would still have to fall back to filtering out
 * outbound rows after the fact instead of the index covering the query's
 * WHERE clause directly. The index name is given explicitly rather than
 * left to Laravel's auto-generated
 * `kapso_whatsapp_messages_account_id_contact_phone_direction_created_at_index`,
 * which exceeds MySQL's 64-character identifier cap and would fail at
 * migrate time.
 *
 * Like every migration in this module, this lands on both the testing DB
 * (picked up automatically by the suite on its next fresh migrate) and the
 * main DB (applied at deploy, per the module's normal release process).
 */
class AddWindowLookupIndexToKapsoWhatsappMessagesTable extends Migration
{
    const INDEX_NAME = 'kwa_messages_window_lookup_index';

    public function up()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->index(['account_id', 'contact_phone', 'direction', 'created_at'], self::INDEX_NAME);
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
}
