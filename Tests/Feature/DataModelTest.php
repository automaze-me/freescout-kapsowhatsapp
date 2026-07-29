<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class DataModelTest extends TestCase
{
    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $mailboxId = $overrides['mailbox_id'] ?? $this->testMailbox()->id;

        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'                => 'Support',
            'phone_number_id'     => '123456789012345',
            'business_account_id' => '999',
            'mailbox_id'          => $mailboxId,
            'is_active'           => true,
        ], $overrides));
        $account->api_key        = 'secret-key';
        $account->webhook_secret = 'secret-hmac';
        $account->save();

        return $account;
    }

    public function test_secrets_are_encrypted_at_rest_but_readable_via_accessor()
    {
        $account = $this->makeAccount();

        $raw = \DB::table('kapso_whatsapp_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('secret-key', $raw->api_key, 'api_key must not be stored in plaintext');
        $this->assertNotSame('secret-hmac', $raw->webhook_secret, 'webhook_secret must not be stored in plaintext');
        $this->assertSame('secret-key', $account->fresh()->api_key);
        $this->assertSame('secret-hmac', $account->fresh()->webhook_secret);
    }

    public function test_find_by_phone_number_id_returns_only_active_accounts()
    {
        $this->makeAccount(['phone_number_id' => '111']);
        $this->makeAccount(['phone_number_id' => '222', 'is_active' => false]);

        $this->assertNotNull(KapsoAccount::findByPhoneNumberId('111'));
        $this->assertNull(KapsoAccount::findByPhoneNumberId('222'));
        $this->assertNull(KapsoAccount::findByPhoneNumberId('333'));
    }

    public function test_seen_detects_a_recorded_wamid()
    {
        $account = $this->makeAccount();

        $this->assertFalse(KapsoMessage::seen('wamid.abc'));

        $message = new KapsoMessage();
        $message->account_id     = $account->id;
        $message->wamid          = 'wamid.abc';
        $message->direction      = KapsoMessage::DIRECTION_INBOUND;
        $message->contact_phone  = '+4915112345678';
        $message->thread_id      = 42;
        $message->save();

        $this->assertTrue(KapsoMessage::seen('wamid.abc'));
        $this->assertSame(42, KapsoMessage::threadForWamid('wamid.abc'));
        $this->assertNull(KapsoMessage::threadForWamid('wamid.unknown'));
    }

    public function test_unreadable_secret_decrypts_to_null_instead_of_throwing()
    {
        $account = $this->makeAccount();

        \DB::table('kapso_whatsapp_accounts')->where('id', $account->id)->update([
            'api_key' => 'not-valid-ciphertext',
        ]);

        $this->assertNull($account->fresh()->api_key);
    }
}
