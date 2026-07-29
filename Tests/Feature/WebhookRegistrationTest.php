<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WebhookRegistrationTest extends TestCase
{
    protected $history = [];

    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'            => 'Support',
            'phone_number_id' => '1'.uniqid(),
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ], $overrides));
        $account->api_key = 'key-abc';
        $account->save();

        return $account;
    }

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    protected function jsonBodyOf(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    public function test_registering_creates_a_webhook_and_stores_its_id_and_generated_secret()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => []])),                        // list: none of ours
            new Response(201, [], json_encode(['data' => ['id' => 'wh-new', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $account = $account->fresh();
        $this->assertSame('wh-new', $account->webhook_id);
        $this->assertSame($url, $account->webhook_url);
        $this->assertTrue($account->webhook_active);
        $this->assertNull($account->webhook_error);
        $this->assertNotNull($account->webhook_checked_at);

        $this->assertNotEmpty($account->webhook_secret);
        $this->assertGreaterThanOrEqual(32, strlen($account->webhook_secret));

        $sent = $this->jsonBodyOf(1)['whatsapp_webhook'];
        $this->assertSame($url, $sent['url']);
        $this->assertSame('kapso', $sent['kind']);
        $this->assertSame('v2', $sent['payload_version']);
        $this->assertTrue($sent['active']);
        $this->assertFalse($sent['buffer_enabled'], 'buffering must be switched off explicitly, not left to a Kapso default');
        $this->assertSame([
            'whatsapp.message.received',
            'whatsapp.message.sent',
            'whatsapp.message.failed',
        ], $sent['events']);
        $this->assertSame($account->webhook_secret, $sent['secret_key'], 'Kapso must be given the same secret we stored');
    }

    /**
     * The trap this whole feature has to avoid: during the parallel run the
     * n8n bridge has its own webhook on the same number. Registering must
     * create ours and leave theirs completely untouched.
     */
    public function test_a_webhook_belonging_to_something_else_is_never_updated()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-n8n', 'url' => 'https://n8n.example.com/webhook/kapso', 'active' => true],
            ]])),
            new Response(201, [], json_encode(['data' => ['id' => 'wh-ours', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-ours', $account->fresh()->webhook_id);
        $this->assertCount(2, $this->history, 'exactly one list and one create');
        $this->assertSame('POST', $this->history[1]['request']->getMethod());

        foreach ($this->history as $entry) {
            $this->assertStringNotContainsString(
                'wh-n8n',
                (string) $entry['request']->getUri(),
                'the bridge webhook must never be addressed'
            );
        }
    }

    public function test_an_existing_webhook_on_our_own_url_is_adopted_rather_than_duplicated()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-mine', 'url' => $url, 'active' => false],
            ]])),
            new Response(200, [], json_encode(['data' => ['id' => 'wh-mine', 'url' => $url, 'active' => true]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-mine', $account->fresh()->webhook_id);
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertStringContainsString('wh-mine', (string) $this->history[1]['request']->getUri());
        $this->assertTrue($this->jsonBodyOf(1)['whatsapp_webhook']['active'], 'adopting must also un-pause');
        $this->assertFalse(
            $this->jsonBodyOf(1)['whatsapp_webhook']['buffer_enabled'],
            'buffering must be switched off explicitly even when adopting an auto-paused webhook'
        );
    }

    /**
     * A URL that merely contains ours as a substring is somebody else's.
     * url_contains is a server-side *substring* filter, so this response is
     * one Kapso can genuinely return.
     */
    public function test_a_url_that_only_contains_ours_as_a_substring_is_not_ours()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-other', 'url' => $url.'/proxy', 'active' => true],
            ]])),
            new Response(201, [], json_encode(['data' => ['id' => 'wh-ours', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-ours', $account->fresh()->webhook_id);
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
    }

    /**
     * After a domain change our stored webhook no longer matches the current
     * URL. Moving it beats orphaning it and creating a second one.
     */
    public function test_a_previously_registered_webhook_is_moved_when_the_install_url_changes()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-old', 'url' => 'https://old.example.com/kapso-whatsapp/webhook', 'active' => true]])),
            new Response(200, [], json_encode(['data' => ['id' => 'wh-old', 'url' => $url, 'active' => true]])),
        ]);

        $account              = $this->makeAccount();
        $account->webhook_id  = 'wh-old';
        $account->webhook_url = 'https://old.example.com/kapso-whatsapp/webhook';
        $account->save();

        (new WebhookRegistrar($account))->register();

        $account = $account->fresh();
        $this->assertSame('wh-old', $account->webhook_id);
        $this->assertSame($url, $account->webhook_url);
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertSame($url, $this->jsonBodyOf(1)['whatsapp_webhook']['url']);
    }

    public function test_a_stored_webhook_deleted_in_kapso_is_re_created()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(404, [], json_encode(['error' => 'Not found'])),               // GET stored id
            new Response(200, [], json_encode(['data' => []])),                         // list: none of ours
            new Response(201, [], json_encode(['data' => ['id' => 'wh-fresh', 'active' => true, 'url' => $url]])),
        ]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-gone';
        $account->save();

        (new WebhookRegistrar($account))->register();

        $this->assertSame('wh-fresh', $account->fresh()->webhook_id);
    }

    public function test_a_rejected_api_key_leaves_the_stored_state_untouched()
    {
        $account                 = $this->makeAccount();
        $account->webhook_id     = 'wh-live';
        $account->webhook_secret = 'old-secret';
        $account->save();

        // A stored webhook_id sends findOwnWebhook() straight to the
        // GET-by-id path, so a single queued response is enough: the 401
        // must be rethrown before any list/create/update call is made.
        $this->fakeResponses([new Response(401, [], json_encode(['error' => 'Invalid API key']))]);

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }

        $account = $account->fresh();
        $this->assertSame('wh-live', $account->webhook_id, 'a failed registration must not lose the stored webhook id');
        $this->assertSame('old-secret', $account->webhook_secret, 'a failed registration must not burn the working secret');
    }

    /**
     * The more dangerous shape than an outright auth rejection: the list
     * call succeeds (so findOwnWebhook() has already talked to Kapso once)
     * and only the following create fails. register() must still write
     * nothing until the create/update call itself has succeeded.
     */
    public function test_a_server_error_on_create_after_a_successful_list_leaves_the_stored_secret_untouched()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => []])),                          // list: none of ours
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),     // create fails
        ]);

        $account                 = $this->makeAccount();
        $account->webhook_secret = 'old-secret';
        $account->save();

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertSame('old-secret', $account->webhook_secret, 'a failed create must not burn the working secret');
    }

    /**
     * The non-404 rethrow in findOwnWebhook() is what stops a transient
     * server error on the id fetch from falling through to listing and
     * creating a duplicate webhook. Pin it down so widening that catch later
     * doesn't silently regress.
     */
    public function test_a_server_error_fetching_the_stored_webhook_stops_before_any_further_request()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'Internal Server Error']))]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-live';
        $account->save();

        try {
            (new WebhookRegistrar($account))->register();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $this->assertCount(1, $this->history, 'a transient error fetching our own webhook must not fall through to listing/creating a duplicate');
    }

    /**
     * The registration URL must follow config('app.url') -- the canonical
     * install address -- not whatever host this particular request happened
     * to arrive on. See Tests/Unit/WebhookUrlTest.php for the full coverage
     * of that derivation, including the subdirectory case.
     */
    public function test_the_webhook_url_is_derived_from_the_configured_app_url()
    {
        $this->assertSame(
            WebhookRegistrar::rootUrl().route('kapsowhatsapp.webhook', [], false),
            WebhookRegistrar::webhookUrl()
        );
    }

    /**
     * Carried over from Task 3's review: register()'s PATCH branch has two
     * fallbacks that were never pinned down -- the `$webhook['id'] ?? $existing['id']`
     * fallback for a 204/{} PATCH response, and the resulting webhook_active
     * staying null (not false) because an empty response tells us nothing.
     */
    public function test_adopting_a_webhook_whose_patch_response_is_empty_still_keeps_the_adopted_id()
    {
        $url = WebhookRegistrar::webhookUrl();

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => [
                ['id' => 'wh-mine', 'url' => $url, 'active' => false],
            ]])),
            new Response(204, [], json_encode([])),
        ]);

        $account = $this->makeAccount();
        (new WebhookRegistrar($account))->register();

        $account = $account->fresh();
        $this->assertSame('wh-mine', $account->webhook_id);
        $this->assertNull($account->webhook_active);
    }

    /**
     * webhook_url is string(255) and Kapso's echoed value is written back
     * verbatim -- a longer value (a redirect-expanded host, an unexpected
     * echo) must be truncated rather than throw on save() under strict SQL
     * mode.
     */
    public function test_refresh_truncates_an_oversized_echoed_url_instead_of_failing_to_save()
    {
        $longUrl = 'https://help.example.com/'.str_repeat('a', 300);

        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'url' => $longUrl, 'active' => true]])),
        ]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-1';
        $account->save();

        (new WebhookRegistrar($account))->refresh();

        $account = $account->fresh();
        $this->assertSame(255, strlen($account->webhook_url));
        $this->assertSame(mb_substr($longUrl, 0, 255), $account->webhook_url);
    }

    public function test_refresh_records_that_kapso_paused_the_webhook_and_why()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'url' => WebhookRegistrar::webhookUrl(), 'active' => false]])),
            new Response(200, [], json_encode(['data' => [
                ['event' => 'whatsapp.message.received', 'status' => 'failed', 'response_status' => 403, 'attempt_count' => 4],
                ['event' => 'whatsapp.message.received', 'status' => 'failed', 'response_status' => 403, 'attempt_count' => 4],
            ]])),
        ]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-1';
        $account->save();

        (new WebhookRegistrar($account))->refresh();

        $account = $account->fresh();
        $this->assertFalse($account->webhook_active);
        $this->assertTrue($account->isWebhookPaused());
        $this->assertStringContainsString('403', $account->webhook_error);
        $this->assertStringNotContainsString('curl', strtolower($account->webhook_error));
    }

    public function test_refresh_clears_a_previous_error_when_the_webhook_is_healthy()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'url' => WebhookRegistrar::webhookUrl(), 'active' => true]])),
        ]);

        $account                = $this->makeAccount();
        $account->webhook_id    = 'wh-1';
        $account->webhook_error = 'something old';
        $account->save();

        (new WebhookRegistrar($account))->refresh();

        $account = $account->fresh();
        $this->assertTrue($account->webhook_active);
        $this->assertNull($account->webhook_error);
        $this->assertCount(1, $this->history, 'a healthy webhook must not trigger a deliveries lookup');
    }

    public function test_refresh_reports_a_webhook_that_was_deleted_in_kapso()
    {
        $this->fakeResponses([new Response(404, [], json_encode(['error' => 'Not found']))]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-gone';
        $account->save();

        $this->assertNull((new WebhookRegistrar($account))->refresh());

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertFalse($account->isWebhookRegistered());
        $this->assertStringContainsString('no longer exists', $account->webhook_error);
    }

    public function test_refresh_does_nothing_when_no_webhook_is_registered()
    {
        $this->fakeResponses([]);

        $this->assertNull((new WebhookRegistrar($this->makeAccount()))->refresh());
        $this->assertCount(0, $this->history);
    }

    public function test_resume_reactivates_our_webhook_without_touching_its_secret()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'active' => true]])),
        ]);

        $account                = $this->makeAccount();
        $account->webhook_id    = 'wh-1';
        $account->webhook_active = false;
        $account->save();

        (new WebhookRegistrar($account))->resume();

        $account = $account->fresh();
        $this->assertTrue($account->webhook_active);
        $this->assertNull($account->webhook_error);

        $sent = $this->jsonBodyOf(0)['whatsapp_webhook'];
        $this->assertSame(['active' => true], $sent, 'resume must send only the active flag');
    }

    /**
     * Before this fix, resume()'s 404 fell through to the generic error
     * mapper and told the admin Kapso does not recognise the Phone Number ID
     * -- pointing at two settings that are correct, on the one button this
     * feature exists to provide. resume() must diagnose a 404 the same way
     * refresh() already does: the webhook is gone, not the account is wrong.
     */
    public function test_resume_reports_a_webhook_that_was_deleted_in_kapso()
    {
        $this->fakeResponses([new Response(404, [], json_encode(['error' => 'Not found']))]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-gone';
        $account->save();

        $this->assertNull((new WebhookRegistrar($account))->resume());

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertFalse($account->isWebhookRegistered());
        $this->assertStringContainsString('no longer exists', $account->webhook_error);
        $this->assertStringNotContainsString('Phone Number ID', $account->webhook_error);
    }

    public function test_resume_without_a_registration_fails_loudly()
    {
        $this->fakeResponses([]);

        $this->expectException(KapsoApiException::class);

        (new WebhookRegistrar($this->makeAccount()))->resume();
    }

    /**
     * resume() must follow the same tri-state rule as register(): a PATCH
     * response that omits `active` (e.g. an empty body from a 204) tells us
     * nothing about the current state, so webhook_active must stay null --
     * not be optimistically set to true just because the PATCH was accepted.
     */
    public function test_resume_against_an_empty_patch_response_leaves_webhook_active_null()
    {
        $this->fakeResponses([
            new Response(204, [], json_encode([])),
        ]);

        $account                 = $this->makeAccount();
        $account->webhook_id     = 'wh-1';
        $account->webhook_active = false;
        $account->save();

        (new WebhookRegistrar($account))->resume();

        $account = $account->fresh();
        $this->assertNull($account->webhook_active);
    }

    /**
     * The non-404 rethrow in refresh() is what stops a transient server error
     * on the status GET from being mistaken for "the webhook was deleted" and
     * wiping the stored webhook_id.
     */
    public function test_refresh_rethrows_a_non_404_error_and_leaves_the_webhook_id_stored()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'Internal Server Error']))]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-1';
        $account->save();

        try {
            (new WebhookRegistrar($account))->refresh();
            $this->fail('Expected KapsoApiException');
        } catch (KapsoApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $this->assertSame('wh-1', $account->fresh()->webhook_id, 'a transient error must not wipe the stored webhook id');
    }
}
