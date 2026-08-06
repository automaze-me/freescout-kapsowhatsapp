<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKapsoWhatsappContactsTable extends Migration
{
    public function up()
    {
        Schema::create('kapso_whatsapp_contacts', function (Blueprint $table) {
            $table->increments('id');
            // Verbatim Meta value, e.g. "US.13491208655302741918" (max 131
            // chars) -- case preserved, never normalised. 191 keeps the
            // unique index inside utf8mb4 key-length limits, this module's
            // existing convention (wamid, kapso_conversation_id).
            $table->string('bsuid', 191)->unique();
            // Parent BSUID ("US.ENT.…", max 135 chars): stored when present,
            // never matched on -- multi-portfolio identity is out of scope
            // (spec Non-goals).
            $table->string('parent_bsuid', 191)->nullable();
            $table->integer('customer_id')->unsigned()->index();
            // Same "+"-prefixed E.164 format as
            // kapso_whatsapp_messages.contact_phone. Indexed for
            // ContactDirectory::captureFromWebhook()'s phone-side lookups.
            $table->string('phone', 32)->nullable()->index();
            $table->string('username', 191)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kapso_whatsapp_contacts');
    }
}
