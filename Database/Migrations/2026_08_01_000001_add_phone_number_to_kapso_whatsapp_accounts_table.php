<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The human-readable number (Kapso's display_phone_number, e.g.
 * "+49 177 5550000") for the admin UI: the Phone Number ID means nothing to
 * a person, so the settings screens show this instead (falling back to the
 * id for accounts created before this column existed -- see
 * KapsoAccount::getDisplayNumberAttribute()).
 *
 * Nullable: Kapso's schema allows a number record with no
 * display_phone_number yet (Meta hasn't confirmed the number), and older
 * accounts have nothing to backfill from without a Kapso round-trip.
 */
class AddPhoneNumberToKapsoWhatsappAccountsTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            // 32 comfortably fits an E.164 number (max 15 digits) plus the
            // spacing/punctuation Meta's display format adds.
            $table->string('phone_number', 32)->nullable()->after('phone_number_id');
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('phone_number');
        });
    }
}
