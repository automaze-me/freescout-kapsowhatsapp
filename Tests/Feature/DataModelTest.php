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

    public function test_webhook_state_defaults_to_unregistered_and_unknown()
    {
        $account = $this->makeAccountForWebhookState();

        $this->assertNull($account->webhook_id);
        $this->assertNull($account->webhook_active, 'webhook_active must stay null until Kapso has actually been asked');
        $this->assertFalse($account->isWebhookRegistered());
        $this->assertFalse($account->isWebhookPaused());
    }

    public function test_a_registered_webhook_reports_registered_paused_and_moved()
    {
        $account                     = $this->makeAccountForWebhookState();
        $account->webhook_id         = '9e8d7c6b-5a4f-3e2d-1c0b-9a8f7e6d5c4b';
        $account->webhook_url        = 'https://help.example.com/kapso-whatsapp/webhook';
        $account->webhook_active     = false;
        $account->webhook_checked_at = now();
        $account->save();

        $account = $account->fresh();

        $this->assertTrue($account->isWebhookRegistered());
        $this->assertTrue($account->isWebhookPaused());
        $this->assertFalse($account->webhookUrlHasMoved('https://help.example.com/kapso-whatsapp/webhook'));
        $this->assertTrue($account->webhookUrlHasMoved('https://new.example.com/kapso-whatsapp/webhook'));
        $this->assertInstanceOf(\Carbon\Carbon::class, $account->webhook_checked_at);
        $this->assertFalse($account->webhook_active, 'webhook_active must round-trip as a real boolean, not "0"');
    }

    /**
     * refresh() writes back whatever URL Kapso echoes, and Kapso's own
     * normalisation -- a trailing slash added, host case changed, an
     * explicit default port spelled out -- must not read as "moved", or the
     * nag becomes permanent with no way to clear it. Path stays
     * case-sensitive: that is a genuinely different endpoint.
     */
    public function test_webhook_url_moved_check_tolerates_cosmetic_differences()
    {
        $account = $this->makeAccountForWebhookState();
        $account->webhook_id = 'wh-1';
        $account->save();

        $cases = [
            'a trailing slash'  => ['https://help.example.com/kapso-whatsapp/webhook', 'https://help.example.com/kapso-whatsapp/webhook/'],
            'host case'         => ['https://Help.Example.com/kapso-whatsapp/webhook', 'https://help.example.com/kapso-whatsapp/webhook'],
            'scheme case'       => ['HTTPS://help.example.com/kapso-whatsapp/webhook', 'https://help.example.com/kapso-whatsapp/webhook'],
            'default https port' => ['https://help.example.com:443/kapso-whatsapp/webhook', 'https://help.example.com/kapso-whatsapp/webhook'],
            'default http port' => ['http://help.example.com:80/kapso-whatsapp/webhook', 'http://help.example.com/kapso-whatsapp/webhook'],
        ];

        foreach ($cases as $label => [$stored, $current]) {
            $account->webhook_url = $stored;

            $this->assertFalse($account->webhookUrlHasMoved($current), $label.' must not be reported as moved');
        }
    }

    public function test_webhook_url_moved_check_still_catches_a_genuine_move()
    {
        $account              = $this->makeAccountForWebhookState();
        $account->webhook_id  = 'wh-1';
        $account->webhook_url = 'https://help.example.com/kapso-whatsapp/webhook';
        $account->save();

        $this->assertTrue($account->webhookUrlHasMoved('https://new.example.com/kapso-whatsapp/webhook'), 'a different host is a real move');
        $this->assertTrue($account->webhookUrlHasMoved('https://help.example.com:8443/kapso-whatsapp/webhook'), 'a different non-default port is a real move');

        // A differently-cased path is deliberately still "moved": only the
        // scheme and host are normalised, because a path really can be
        // case-sensitive on the server side.
        $this->assertTrue($account->webhookUrlHasMoved('https://help.example.com/Kapso-Whatsapp/webhook'), 'a differently-cased path is a real move');
    }

    /**
     * An account that has never been registered must not be reported as
     * "paused" -- paused is a statement about a webhook that exists.
     */
    public function test_an_unregistered_account_is_never_reported_as_paused()
    {
        $account                 = $this->makeAccountForWebhookState();
        $account->webhook_active = false;
        $account->save();

        $this->assertFalse($account->fresh()->isWebhookPaused());
    }

    protected function makeAccountForWebhookState(): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Support',
            'phone_number_id' => '1'.uniqid(),
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->api_key = 'key';
        $account->save();

        return $account;
    }
}
