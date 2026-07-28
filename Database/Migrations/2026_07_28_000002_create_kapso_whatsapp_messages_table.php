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
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kapso_whatsapp_messages');
    }
}
