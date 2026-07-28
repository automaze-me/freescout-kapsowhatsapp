<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKapsoWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 191);
            $table->string('phone_number_id', 64)->unique();
            $table->string('business_account_id', 64)->nullable();
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->integer('mailbox_id')->unsigned()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kapso_whatsapp_accounts');
    }
}
