<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContactBsuidToKapsoWhatsappMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            // The contact's business-scoped user ID, verbatim, alongside
            // contact_phone. Either column may be NULL; inbound processing
            // guarantees at least one is set on rows it writes. Indexed for
            // WindowState's contact matching and
            // ReconcileOutboundMessage's BSUID resolution leg.
            $table->string('contact_bsuid', 191)->nullable()->index()->after('contact_phone');
        });
    }

    public function down()
    {
        Schema::table('kapso_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['contact_bsuid']);
            $table->dropColumn('contact_bsuid');
        });
    }
}
