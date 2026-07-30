<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSendColumnsToKapsoWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            // One row per outbound message part ('body', 'body:2', 'att:<id>').
            // NULL on every inbound/reconcile-written row. The unique index
            // below is the send-once claim: MySQL permits any number of NULL
            // part_key rows per thread, so inbound rows are unaffected, while
            // two workers claiming the same part race on a plain unique key.
            $table->string('part_key', 32)->nullable()->after('attachment_id');
            // Module-owned send lifecycle: sending -> accepted|failed.
            // Deliberately NOT the `status` column, whose docblock restricts
            // it to Kapso's own vocabulary arriving via webhooks.
            $table->string('send_state', 16)->nullable()->after('part_key');
            $table->unique(['thread_id', 'part_key']);
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->dropUnique(['thread_id', 'part_key']);
            $table->dropColumn(['part_key', 'send_state']);
        });
    }
}
