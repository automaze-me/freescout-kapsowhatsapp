<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Customer;
use App\CustomerChannel;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

require_once __DIR__.'/../../Database/Migrations/2026_08_02_000001_update_channel_code_to_105.php';

class ChannelCodeMigrationTest extends TestCase
{
    /**
     * The new module code writes channel-105 rows from the moment it goes
     * live, and on some installs the migration only runs at the next
     * container boot — so by the time remap() executes, an inbound message
     * may already have created the (105, phone) row the bulk UPDATE would
     * move the old (102, phone) row onto. That exact sequence took a
     * production install down with a unique-constraint violation on
     * (channel, channel_id).
     */
    public function test_up_survives_a_105_row_already_created_by_live_code()
    {
        $phone = '+4915170000001';

        $old = Customer::createWithoutEmail(['first_name' => 'Historie', 'last_name' => 'Kunde']);
        CustomerChannel::create($old->id, 102, $phone);

        $interim = Customer::createWithoutEmail(['first_name' => 'Zwischen', 'last_name' => 'Kunde']);
        CustomerChannel::create($interim->id, 105, $phone);

        $this->recordInboundPhone($phone);

        (new \UpdateChannelCodeTo105())->up();

        $rows = CustomerChannel::where('channel_id', $phone)->get();

        $this->assertCount(1, $rows, 'exactly one channel row must remain for the phone');
        $this->assertSame(105, (int) $rows->first()->channel);
        $this->assertSame(
            $interim->id,
            (int) $rows->first()->customer_id,
            'the row live code already resolves through must survive; the stale 102 row is the one to drop'
        );
    }

    public function test_up_remaps_scoped_rows_and_leaves_foreign_102_rows_alone()
    {
        $ours = Customer::createWithoutEmail(['first_name' => 'Nur', 'last_name' => 'Alt']);
        CustomerChannel::create($ours->id, 102, '+4915170000002');
        $this->recordInboundPhone('+4915170000002');

        $foreign = Customer::createWithoutEmail(['first_name' => 'Fremd', 'last_name' => 'Modul']);
        CustomerChannel::create($foreign->id, 102, 'some-third-party-identity');

        (new \UpdateChannelCodeTo105())->up();

        $this->assertSame(105, (int) CustomerChannel::where('channel_id', '+4915170000002')->value('channel'));
        $this->assertSame(102, (int) CustomerChannel::where('channel_id', 'some-third-party-identity')->value('channel'));
    }

    public function test_down_survives_a_102_row_that_never_got_remapped()
    {
        $phone = '+4915170000003';

        $stray = Customer::createWithoutEmail(['first_name' => 'Streuner', 'last_name' => 'Alt']);
        CustomerChannel::create($stray->id, 102, $phone);

        $current = Customer::createWithoutEmail(['first_name' => 'Aktuell', 'last_name' => 'Neu']);
        CustomerChannel::create($current->id, 105, $phone);

        $this->recordInboundPhone($phone);

        (new \UpdateChannelCodeTo105())->down();

        $rows = CustomerChannel::where('channel_id', $phone)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(102, (int) $rows->first()->channel);
        $this->assertSame($stray->id, (int) $rows->first()->customer_id);
    }

    /**
     * The remap scopes itself to phones this module recorded, so every
     * scenario needs a matching kapso_whatsapp_messages row. account_id is
     * a plain unconstrained column — no real account row is required.
     */
    protected function recordInboundPhone(string $phone): void
    {
        $message = new KapsoMessage();
        $message->account_id    = 424242;
        $message->wamid         = 'wamid.migration-'.md5($phone);
        $message->direction     = KapsoMessage::DIRECTION_INBOUND;
        $message->contact_phone = $phone;
        $message->save();
    }
}
