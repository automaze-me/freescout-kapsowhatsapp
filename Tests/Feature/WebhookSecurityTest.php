<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    protected $secret = 'test-webhook-secret';

    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ], $overrides));
        $account->api_key        = 'key';
        $account->webhook_secret = $this->secret;
        $account->save();

        return $account;
    }

    protected function payload(string $wamid = 'wamid.1', string $phoneNumberId = '123456789012345'): array
    {
        return [
            'message' => [
                'id'        => $wamid,
                'timestamp' => '1730092800',
                'type'      => 'text',
                'from'      => '4915112345678',
                'text'      => ['body' => 'Hello'],
                'kapso'     => ['direction' => 'inbound', 'content' => 'Hello'],
            ],
            'conversation' => [
                'id'              => 'conv_1',
                'phone_number_id' => $phoneNumberId,
                'kapso'           => ['contact_name' => 'Anna Weber'],
            ],
            'is_new_conversation' => true,
            'phone_number_id'     => $phoneNumberId,
        ];
    }

    protected function postWebhook(array $payload, ?string $signature, string $event = 'whatsapp.message.received', array $extraHeaders = [])
    {
        $body = json_encode($payload);

        $headers = array_merge([
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'    => $event,
            'HTTP_X_IDEMPOTENCY_KEY'  => 'idem-'.md5($body),
        ], $extraHeaders);

        if ($signature !== null) {
            $headers['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/kapso-whatsapp/webhook', [], [], [], $headers, $body);
    }

    protected function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), $this->secret);
    }

    public function test_valid_signature_is_accepted()
    {
        $this->makeAccount();
        $payload = $this->payload();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(200);
    }

    public function test_invalid_signature_is_rejected()
    {
        $this->makeAccount();
        $payload = $this->payload();

        $this->postWebhook($payload, hash_hmac('sha256', json_encode($payload), 'wrong-secret'))
            ->assertStatus(403);
    }

    public function test_missing_signature_is_rejected()
    {
        $this->makeAccount();

        $this->postWebhook($this->payload(), null)->assertStatus(403);
    }

    public function test_unknown_phone_number_id_is_rejected()
    {
        $this->makeAccount();
        $payload = $this->payload('wamid.1', '999999999999999');

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(403);
    }

    public function test_inactive_account_is_rejected()
    {
        $this->makeAccount(['is_active' => false]);
        $payload = $this->payload();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(403);
    }

    public function test_malformed_json_is_rejected()
    {
        $this->makeAccount();

        $response = $this->call('POST', '/kapso-whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'   => 'whatsapp.message.received',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', 'not json', $this->secret),
        ], 'not json');

        $response->assertStatus(403);
    }

    public function test_replayed_wamid_returns_200_without_reprocessing()
    {
        $account = $this->makeAccount();
        $payload = $this->payload();

        $message = new KapsoMessage();
        $message->account_id    = $account->id;
        $message->wamid         = 'wamid.1';
        $message->direction     = KapsoMessage::DIRECTION_INBOUND;
        $message->contact_phone = '+4915112345678';
        $message->save();

        \Queue::fake();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(200);

        \Queue::assertNothingPushed();
    }

    public function test_a_failed_event_is_not_swallowed_by_the_wamid_of_its_own_send()
    {
        $account = $this->makeAccount();

        // The send was recorded under this wamid; the failure event reuses it.
        $message = new KapsoMessage();
        $message->account_id    = $account->id;
        $message->wamid         = 'wamid.1';
        $message->direction     = KapsoMessage::DIRECTION_OUTBOUND;
        $message->contact_phone = '+4915112345678';
        $message->save();

        \Queue::fake();

        $payload = $this->payload();
        $this->postWebhook($payload, $this->sign($payload), 'whatsapp.message.failed')->assertStatus(200);

        \Queue::assertPushed(\Modules\KapsoWhatsApp\Jobs\ReconcileOutboundMessage::class);
    }

    public function test_a_repeated_delivery_of_the_same_idempotency_key_is_skipped()
    {
        $this->makeAccount();
        $payload = $this->payload();

        \Queue::fake();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(200);
        $this->postWebhook($payload, $this->sign($payload))->assertStatus(200);

        \Queue::assertPushed(\Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage::class, 1);
    }

    public function test_webhook_route_is_not_behind_csrf()
    {
        // A POST without a CSRF token must not 419. If this fails, the route
        // was registered inside the 'web' middleware group.
        $this->makeAccount();
        $payload = $this->payload();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(200);
    }

    public function test_last_webhook_at_is_recorded()
    {
        $account = $this->makeAccount();
        $payload = $this->payload();

        $this->postWebhook($payload, $this->sign($payload));

        $this->assertNotNull($account->fresh()->last_webhook_at);
    }
}
