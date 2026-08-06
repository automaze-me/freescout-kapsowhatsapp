<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Entities\KapsoContact;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class BsuidSchemaTest extends TestCase
{
    public function test_contact_directory_row_round_trips()
    {
        $contact = KapsoContact::create([
            'bsuid'        => 'US.13491208655302741918',
            'parent_bsuid' => 'US.ENT.506847293015824',
            'customer_id'  => 1,
            'phone'        => '+4915100000001',
            'username'     => '@testusername',
        ]);

        $fresh = KapsoContact::where('bsuid', 'US.13491208655302741918')->first();

        $this->assertNotNull($fresh);
        $this->assertSame($contact->id, $fresh->id);
        $this->assertSame('US.ENT.506847293015824', $fresh->parent_bsuid);
        $this->assertSame(1, (int) $fresh->customer_id);
        $this->assertSame('+4915100000001', $fresh->phone);
        $this->assertSame('@testusername', $fresh->username);
    }

    public function test_bsuid_is_unique()
    {
        KapsoContact::create(['bsuid' => 'US.DupCheck1', 'customer_id' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        KapsoContact::create(['bsuid' => 'US.DupCheck1', 'customer_id' => 2]);
    }

    public function test_message_rows_carry_a_contact_bsuid()
    {
        $row = new KapsoMessage();
        $row->account_id    = 1;
        $row->direction     = KapsoMessage::DIRECTION_INBOUND;
        $row->wamid         = 'wamid.schema.bsuid';
        $row->contact_bsuid = 'US.13491208655302741918';
        $row->save();

        $this->assertSame(
            'US.13491208655302741918',
            KapsoMessage::where('wamid', 'wamid.schema.bsuid')->value('contact_bsuid')
        );

        // Mass assignment must work too: ProcessInboundMessage and
        // ReconcileOutboundMessage write rows via KapsoMessage::create().
        $created = KapsoMessage::create([
            'account_id'    => 1,
            'direction'     => KapsoMessage::DIRECTION_INBOUND,
            'wamid'         => 'wamid.schema.bsuid.2',
            'contact_bsuid' => 'US.13491208655302741918',
        ]);

        $this->assertSame('US.13491208655302741918', $created->fresh()->contact_bsuid);
    }
}
