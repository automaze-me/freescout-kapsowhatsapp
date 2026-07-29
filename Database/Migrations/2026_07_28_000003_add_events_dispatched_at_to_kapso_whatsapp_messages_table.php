<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEventsDispatchedAtToKapsoWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            // Marks the moment the core FreeScout events/Eventy hooks were
            // confirmed dispatched for this message. NULL means the
            // conversation/thread/dedupe row committed but the events were
            // never confirmed fired (e.g. a listener threw, or the worker
            // died between commit and dispatch) — a retry must still fire
            // them instead of silently skipping because the row is "seen".
            $table->timestamp('events_dispatched_at')->nullable()->after('error');
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('events_dispatched_at');
        });
    }
}
