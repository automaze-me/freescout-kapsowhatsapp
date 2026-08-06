<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Entities\KapsoContact;
use Modules\KapsoWhatsApp\Services\ContactDirectory;
use Modules\KapsoWhatsApp\Tests\TestCase;

class ContactDirectoryTest extends TestCase
{
    public function test_valid_bsuid_formats()
    {
        $this->assertTrue(ContactDirectory::isValidBsuid('US.13491208655302741918'));
        $this->assertTrue(ContactDirectory::isValidBsuid('US.ENT.506847293015824'));
        // Case-insensitive per the /i modifier; stored verbatim either way.
        $this->assertTrue(ContactDirectory::isValidBsuid('us.abc123'));
        $this->assertTrue(ContactDirectory::isValidBsuid('DE.'.str_repeat('a', 128)));
    }

    public function test_invalid_bsuid_formats()
    {
        $this->assertFalse(ContactDirectory::isValidBsuid(null));
        $this->assertFalse(ContactDirectory::isValidBsuid(''));
        $this->assertFalse(ContactDirectory::isValidBsuid('+4915112345678'));
        $this->assertFalse(ContactDirectory::isValidBsuid('4915112345678'));
        $this->assertFalse(ContactDirectory::isValidBsuid('USA.123'));
        $this->assertFalse(ContactDirectory::isValidBsuid('US.'));
        $this->assertFalse(ContactDirectory::isValidBsuid('US.ENT.'));
        $this->assertFalse(ContactDirectory::isValidBsuid('US.abc def'));
        $this->assertFalse(ContactDirectory::isValidBsuid('US.'.str_repeat('a', 129)));
        $this->assertFalse(ContactDirectory::isValidBsuid(12345));
    }

    public function test_extract_inbound_prefers_message_fields_over_conversation()
    {
        $identity = (new ContactDirectory())->extractInbound([
            'message'      => [
                'business_scoped_user_id' => 'US.MessageLevel1',
                'from_user_id'            => 'US.FromUserId1',
                'username'                => '@messagename',
            ],
            'conversation' => [
                'business_scoped_user_id' => 'US.ConversationLevel1',
                'username'                => '@conversationname',
            ],
        ]);

        $this->assertSame('US.MessageLevel1', $identity['bsuid']);
        $this->assertSame('@messagename', $identity['username']);
    }

    public function test_extract_inbound_falls_back_to_from_user_id_then_conversation()
    {
        $directory = new ContactDirectory();

        $viaFromUserId = $directory->extractInbound([
            'message'      => ['from_user_id' => 'US.FromUserId2'],
            'conversation' => ['business_scoped_user_id' => 'US.ConversationLevel2'],
        ]);
        $this->assertSame('US.FromUserId2', $viaFromUserId['bsuid']);

        $viaConversation = $directory->extractInbound([
            'message'      => ['id' => 'wamid.x'],
            'conversation' => [
                'business_scoped_user_id'        => 'US.ConversationLevel3',
                'parent_business_scoped_user_id' => 'US.ENT.Parent3',
                'username'                       => '@convname3',
            ],
        ]);
        $this->assertSame('US.ConversationLevel3', $viaConversation['bsuid']);
        $this->assertSame('US.ENT.Parent3', $viaConversation['parent_bsuid']);
        $this->assertSame('@convname3', $viaConversation['username']);
    }

    public function test_extract_treats_malformed_bsuid_as_absent()
    {
        $identity = (new ContactDirectory())->extractInbound([
            'message' => ['from_user_id' => 'not-a-bsuid', 'username' => '   '],
        ]);

        $this->assertNull($identity['bsuid']);
        $this->assertNull($identity['parent_bsuid']);
        $this->assertNull($identity['username']);
    }

    public function test_extract_outbound_reads_to_user_id()
    {
        $identity = (new ContactDirectory())->extractOutbound([
            'message'      => ['to_user_id' => 'US.Recipient1'],
            'conversation' => ['business_scoped_user_id' => 'US.ConversationLevel4'],
        ]);

        $this->assertSame('US.Recipient1', $identity['bsuid']);
    }

    public function test_customer_id_for_returns_the_mapped_id_or_null()
    {
        KapsoContact::create(['bsuid' => 'US.Lookup1', 'customer_id' => 42]);

        $directory = new ContactDirectory();

        $this->assertSame(42, $directory->customerIdFor('US.Lookup1'));
        $this->assertNull($directory->customerIdFor('US.Unknown1'));
        $this->assertNull($directory->customerIdFor(null));
    }

    public function test_record_creates_then_refreshes_attrs_without_repointing_customer()
    {
        $directory = new ContactDirectory();

        $directory->record('US.Record1', 7, ['phone' => '+4915100000010']);

        $row = KapsoContact::where('bsuid', 'US.Record1')->first();
        $this->assertSame(7, (int) $row->customer_id);
        $this->assertSame('+4915100000010', $row->phone);
        $this->assertNull($row->username);

        // Second record: new attrs are refreshed, customer_id is NOT.
        $directory->record('US.Record1', 99, [
            'phone'        => '+4915100000011',
            'username'     => '@newname',
            'parent_bsuid' => 'US.ENT.Parent1',
        ]);

        $row = $row->fresh();
        $this->assertSame(7, (int) $row->customer_id, 'record() must never repoint customer_id');
        $this->assertSame('+4915100000011', $row->phone);
        $this->assertSame('@newname', $row->username);
        $this->assertSame('US.ENT.Parent1', $row->parent_bsuid);

        $this->assertSame(1, KapsoContact::where('bsuid', 'US.Record1')->count());
    }
}
