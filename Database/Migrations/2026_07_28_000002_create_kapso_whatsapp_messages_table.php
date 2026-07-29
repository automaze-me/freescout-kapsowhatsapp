<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKapsoWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::create('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->unsigned()->index();
            $table->integer('conversation_id')->unsigned()->nullable()->index();
            $table->integer('thread_id')->unsigned()->nullable()->index();
            $table->integer('attachment_id')->unsigned()->nullable();
            $table->string('wamid', 191)->nullable()->unique();
            $table->string('kapso_conversation_id', 191)->nullable()->index();
            $table->string('direction', 16);
            $table->string('status', 32)->nullable();
            $table->string('contact_phone', 32)->nullable()->index();
            $table->text('error')->nullable();
            // Marks the moment the core FreeScout events/Eventy hooks were
            // confirmed dispatched for this message. NULL means either the
            // conversation/thread/dedupe row hasn't been claimed for dispatch
            // yet, or (for an inbound row) it committed but the events were
            // never confirmed fired (e.g. a listener threw, or the worker
            // died between commit and dispatch) — a retry must still fire
            // them instead of silently skipping because the row is "seen".
            // Inbound-only: outbound rows (written by ReconcileOutboundMessage)
            // never populate this column.
            $table->timestamp('events_dispatched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kapso_whatsapp_messages');
    }
}
