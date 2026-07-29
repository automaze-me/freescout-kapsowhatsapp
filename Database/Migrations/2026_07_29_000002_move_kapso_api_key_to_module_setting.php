<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\KapsoWhatsApp\Services\Settings;

class MoveKapsoApiKeyToModuleSetting extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('kapso_whatsapp_accounts', 'api_key')) {
            return;
        }

        // The column already held ciphertext (KapsoAccount encrypted it in a
        // mutator) and Settings decrypts on read, so the value moves verbatim
        // -- an install that already had a key keeps working without the admin
        // re-entering it.
        $existing = DB::table('kapso_whatsapp_accounts')
            ->whereNotNull('api_key')
            ->where('api_key', '<>', '')
            ->value('api_key');

        if ($existing && !\App\Option::get(Settings::API_KEY_OPTION, '')) {
            \App\Option::set(Settings::API_KEY_OPTION, $existing);
            unset(\App\Option::$cache[Settings::API_KEY_OPTION]);
        }

        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }

    /**
     * The column comes back empty: the per-account keys it held were collapsed
     * into a single module option going up and cannot be redistributed.
     */
    public function down()
    {
        if (Schema::hasColumn('kapso_whatsapp_accounts', 'api_key')) {
            return;
        }

        Schema::table('kapso_whatsapp_accounts', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('business_account_id');
        });
    }
}
