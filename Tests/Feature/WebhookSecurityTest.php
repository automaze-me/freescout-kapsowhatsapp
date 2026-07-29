<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoMessage;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WebhookSecurityTest extends TestCase
{
    protected $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('key');
    }

    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ], $overrides));
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

    /**
     * The idempotency key defaults to something unique per call (not derived
     * from the body) so tests don't cross-contaminate under a persistent cache
     * driver: every test using the default payload would otherwise generate
     * the identical 'idem-'.md5($body) key. Pass $idempotencyKey explicitly
     * for the test that deliberately repeats one.
     */
    protected function postWebhook(array $payload, ?string $signature, string $event = 'whatsapp.message.received', array $extraHeaders = [], ?string $idempotencyKey = null)
    {
        $body = json_encode($payload);

        $headers = array_merge([
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'    => $event,
            'HTTP_X_IDEMPOTENCY_KEY'  => $idempotencyKey ?? 'idem-'.bin2hex(random_bytes(8)),
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
        $payload        = $this->payload();
        $idempotencyKey = 'idem-repeat-'.md5(json_encode($payload));

        \Queue::fake();

        $this->postWebhook($payload, $this->sign($payload), 'whatsapp.message.received', [], $idempotencyKey)->assertStatus(200);
        $this->postWebhook($payload, $this->sign($payload), 'whatsapp.message.received', [], $idempotencyKey)->assertStatus(200);

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

    /**
     * C1: a non-empty array/object for phone_number_id must not reach the
     * (string) cast in KapsoAccount::findByPhoneNumberId() -- that used to
     * raise an uncaught "Array to string conversion" ErrorException (500),
     * with no signature required at all.
     */
    public function test_array_phone_number_id_is_rejected()
    {
        $this->makeAccount();

        $body    = json_encode(['phone_number_id' => ['a' => 1]]);
        $headers = [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => 'whatsapp.message.received',
        ];

        $this->call('POST', '/kapso-whatsapp/webhook', [], [], [], $headers, $body)
            ->assertStatus(403);
    }

    /**
     * A secret written before an APP_KEY rotation can't be decrypted.
     * KapsoAccount::decryptOrNull() already turns that into a null rather than
     * throwing; this asserts the middleware then correctly treats a null
     * secret as "reject", not "crash".
     */
    public function test_undecryptable_secret_is_rejected()
    {
        $account = $this->makeAccount();

        \DB::table('kapso_whatsapp_accounts')->where('id', $account->id)->update([
            'webhook_secret' => 'not-a-validly-encrypted-payload',
        ]);

        $payload = $this->payload();

        $this->postWebhook($payload, $this->sign($payload))->assertStatus(403);
    }

    public function test_scalar_json_body_is_rejected()
    {
        $this->makeAccount();

        $body    = '123';
        $headers = [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => 'whatsapp.message.received',
        ];

        $this->call('POST', '/kapso-whatsapp/webhook', [], [], [], $headers, $body)
            ->assertStatus(403);
    }

    /**
     * I4: the headline invariant, plus the regression it was written to catch.
     * The idempotency key must only be committed once the job is actually
     * queued -- otherwise a transient queue outage combined with the old
     * "commit the key, then dispatch" ordering would cause every one of
     * Kapso's retries to be silently swallowed and the message lost forever.
     *
     * A dispatch/infrastructure failure must return a non-200 (503), not 200:
     * Kapso only retries a delivery that did NOT get a 200 response, so a 200
     * here would tell Kapso the message was handled when it was never even
     * queued -- permanently losing it the moment Kapso stops retrying. The
     * previous version of this test asserted 200 on the first (failing)
     * delivery while its own comment and its second assertion assumed Kapso
     * would retry -- a 200 response guarantees the opposite.
     */
    public function test_a_job_dispatch_failure_returns_503_so_kapso_retries_and_does_not_burn_the_retry()
    {
        $this->makeAccount();
        $payload        = $this->payload();
        $idempotencyKey = 'idem-dispatch-failure';
        $cacheKey       = 'kapsowhatsapp.idem.'.md5($idempotencyKey);

        $failing = true;

        // A closure capturing $failing by reference, so flipping $failing below
        // changes what the already-bound dispatcher does on the next call.
        $isFailing = function () use (&$failing) {
            return $failing;
        };

        $this->app->bind(\Illuminate\Contracts\Bus\Dispatcher::class, function () use ($isFailing) {
            return new class($isFailing) implements \Illuminate\Contracts\Bus\Dispatcher {
                private $isFailing;

                public function __construct(callable $isFailing)
                {
                    $this->isFailing = $isFailing;
                }

                public function dispatch($command)
                {
                    if (($this->isFailing)()) {
                        throw new \RuntimeException('queue backend unavailable');
                    }
                }

                public function dispatchNow($command, $handler = null)
                {
                    return $this->dispatch($command);
                }

                public function pipeThrough(array $pipes)
                {
                    return $this;
                }
            };
        });

        // First delivery: the queue is down. Kapso must see a non-200 so it
        // actually retries -- a 200 here would tell Kapso the delivery was
        // handled and it would never come back, permanently losing the
        // message. The key must NOT be committed either.
        $this->postWebhook($payload, $this->sign($payload), 'whatsapp.message.received', [], $idempotencyKey)
            ->assertStatus(503);

        $this->assertFalse(\Cache::has($cacheKey), 'idempotency key must not be committed when dispatch fails');

        // Queue recovers; Kapso retries the exact same delivery.
        $failing = false;

        $this->postWebhook($payload, $this->sign($payload), 'whatsapp.message.received', [], $idempotencyKey)
            ->assertStatus(200);

        $this->assertTrue(\Cache::has($cacheKey), 'the retry must actually be processed, not lost');
    }

    /**
     * I5: the existing tests build their body via json_encode(), which for
     * this payload is a fixed point of json_encode(json_decode($body)) -- so
     * a middleware that (wrongly) HMACs the re-encoded parsed array instead of
     * the raw bytes would pass every one of them. This posts a hand-written
     * raw string -- non-canonical spacing, different key order, and a
     * non-ASCII character -- so it only passes if the raw bytes were signed.
     */
    public function test_signature_is_verified_over_the_exact_raw_body_not_a_reencoded_copy()
    {
        $this->makeAccount();

        $raw = '{ "phone_number_id" : "123456789012345", "conversation" : { "id" : "conv_1", '
            .'"phone_number_id" : "123456789012345", "kapso" : { "contact_name" : "Anna Weber" } }, '
            .'"message" : { "kapso" : { "direction" : "inbound", "content" : "Hällo" }, '
            .'"type" : "text", "id" : "wamid.raw1", "timestamp" : "1730092800", '
            .'"from" : "4915112345678", "text" : { "body" : "Hällo" } }, "is_new_conversation" : true }';

        // Self-check: this string must genuinely discriminate -- if it were a
        // fixed point of re-encoding, a middleware hashing the parsed-and-
        // reencoded array would pass too, and this test would be worthless.
        $this->assertNotSame($raw, json_encode(json_decode($raw, true)));

        $headers = [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'     => 'whatsapp.message.received',
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $raw, $this->secret),
            'HTTP_X_IDEMPOTENCY_KEY'   => 'idem-raw-body-test',
        ];

        \Queue::fake();

        $this->call('POST', '/kapso-whatsapp/webhook', [], [], [], $headers, $raw)
            ->assertStatus(200);

        \Queue::assertPushed(\Modules\KapsoWhatsApp\Jobs\ProcessInboundMessage::class, 1);
    }
}
