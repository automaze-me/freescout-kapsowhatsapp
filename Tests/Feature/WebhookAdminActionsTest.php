<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
use Modules\KapsoWhatsApp\Tests\TestCase;

class WebhookAdminActionsTest extends TestCase
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

    public function test_an_admin_can_register_the_webhook()
    {
        $url = WebhookRegistrar::webhookUrl();
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => []])),
            new Response(201, [], json_encode(['data' => ['id' => 'wh-1', 'active' => true, 'url' => $url]])),
        ]);

        $account = $this->makeAccount();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.register', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_success_floating');

        $this->assertSame('wh-1', $account->fresh()->webhook_id);
    }

    /**
     * The rule for this whole slice: a bad key produces an error demanding a
     * valid key, and never a manual registration instruction.
     */
    public function test_a_rejected_api_key_shows_an_error_demanding_a_valid_key()
    {
        $this->fakeResponses([new Response(401, [], json_encode(['error' => 'Invalid API key']))]);

        $account = $this->makeAccount();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.register', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_error_floating');

        $message = session('flash_error_floating');
        $this->assertStringContainsString('API key', $message);
        $this->assertStringNotContainsString('curl', strtolower($message));

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertStringContainsString('API key', $account->webhook_error);
    }

    public function test_an_account_without_an_api_key_is_told_to_add_one()
    {
        $this->fakeResponses([]);

        $account          = $this->makeAccount();
        $account->api_key = null;
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.register', ['id' => $account->id]))
            ->assertStatus(302);

        $this->assertStringContainsString('API key', session('flash_error_floating'));
        $this->assertCount(0, $this->history);
    }

    public function test_an_admin_can_resume_a_paused_webhook()
    {
        $this->fakeResponses([new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'active' => true]]))]);

        $account                 = $this->makeAccount();
        $account->webhook_id     = 'wh-1';
        $account->webhook_active = false;
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_success_floating');

        $this->assertTrue($account->fresh()->webhook_active);
    }

    /**
     * Before this fix, resume() had no 404 branch, so a webhook Kapso had
     * deleted made the button tell the admin to check the Phone Number ID
     * and API key -- both correct -- instead of naming the actual problem.
     */
    public function test_resuming_a_webhook_that_kapso_deleted_names_it_as_gone()
    {
        $this->fakeResponses([new Response(404, [], json_encode(['error' => 'Not found']))]);

        $account                     = $this->makeAccount();
        $account->webhook_id         = 'wh-gone';
        $account->webhook_active     = false;
        $account->webhook_checked_at = now();
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_success_floating');

        $message = session('flash_success_floating');
        $this->assertStringContainsString('no longer exists', $message);
        $this->assertStringNotContainsString('Phone Number ID', $message);

        $account = $account->fresh();
        $this->assertNull($account->webhook_id);
        $this->assertFalse($account->isWebhookRegistered());
    }

    /**
     * The bug this protects: recordWebhookError() used to stamp
     * webhook_checked_at on every failure, including this one. A failed
     * check is not a check -- stamping it made refreshStaleWebhookStatus()
     * treat a transient failure as freshly-learned state, so a wrong
     * diagnosis could stick with no way for the next page load to
     * self-correct it.
     */
    public function test_a_failed_resume_does_not_make_the_status_look_freshly_known()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'boom']))]);

        $account                     = $this->makeAccount();
        $account->webhook_id         = 'wh-1';
        $account->webhook_active     = false;
        $account->webhook_checked_at = now()->subHour();
        $account->save();
        $staleTimestamp = $account->webhook_checked_at->timestamp;

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_error_floating');

        $account = $account->fresh();
        $this->assertNotNull($account->webhook_error);
        $this->assertSame($staleTimestamp, $account->webhook_checked_at->timestamp, 'a failed check must not stamp webhook_checked_at -- it never learned the current state');
    }

    /**
     * The other half of that same fix, and the property that regressed when
     * webhook_checked_at stopped being stamped on failure: without a
     * separate attempted-at marker, a Kapso that keeps failing (degraded,
     * not fully down) would be re-called on every single settings-page load
     * with no backoff at all -- N accounts costing N outbound calls, each up
     * to connect_timeout + timeout, on every load, for as long as Kapso stays
     * unwell. A failed attempt must still count against the outbound call
     * budget even though it does not count as knowledge.
     */
    public function test_a_failed_check_suppresses_the_next_outbound_call_from_the_settings_page()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'boom']))]);

        $account                     = $this->makeAccount();
        $account->webhook_id         = 'wh-1';
        $account->webhook_active     = false;
        $account->webhook_checked_at = now()->subHour();
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_error_floating');

        // Nothing queued: any outbound call here would fail the test with an
        // "empty mock queue" error rather than silently passing.
        $this->fakeResponses([]);

        $this->actingAs($this->adminUser())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $this->assertCount(0, $this->history, 'a check attempted moments ago -- even a failed one -- must not be retried immediately');
    }

    public function test_an_admin_can_refresh_the_status()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'url' => WebhookRegistrar::webhookUrl(), 'active' => true]])),
        ]);

        $account             = $this->makeAccount();
        $account->webhook_id = 'wh-1';
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]))
            ->assertStatus(302);

        $this->assertTrue($account->fresh()->webhook_active);
    }

    /**
     * refreshWebhook() is only reachable by a direct POST (the settings page
     * never renders a "Check now" button for an unregistered account -- it
     * renders "Register with Kapso" instead), but hitting it that way used to
     * flash "Webhook status updated." even though nothing was registered and
     * no check was ever made.
     */
    public function test_refreshing_an_account_with_nothing_registered_says_so()
    {
        $this->fakeResponses([]);

        $account = $this->makeAccount();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]))
            ->assertStatus(302)
            ->assertSessionHas('flash_success_floating');

        $this->assertStringContainsString('Nothing is registered', session('flash_success_floating'));
        $this->assertCount(0, $this->history, 'nothing registered means nothing to check');
    }

    public function test_non_admins_cannot_use_any_webhook_action()
    {
        $this->fakeResponses([]);

        $account = $this->makeAccount();
        $user    = $this->regularUser();

        foreach (['register', 'refresh', 'resume'] as $action) {
            $this->actingAs($user)
                ->post(route('kapsowhatsapp.webhook.'.$action, ['id' => $account->id]))
                ->assertStatus(403);
        }

        $this->assertCount(0, $this->history);
    }

    /**
     * The settings page refreshes stale statuses on load -- that is what makes
     * an auto-pause visible without anyone clicking anything. Asserted on the
     * rendered response body, not just the model: the mechanism this test
     * exists to protect is refresh -> render, and asserting only
     * $account->fresh()->webhook_active would still pass if a future refactor
     * (e.g. re-querying $accounts after the refresh loop instead of mutating
     * in place) silently broke the view's half of that seam while every
     * other test stayed green.
     */
    public function test_the_settings_page_refreshes_a_stale_status()
    {
        $this->fakeResponses([
            new Response(200, [], json_encode(['data' => ['id' => 'wh-1', 'url' => WebhookRegistrar::webhookUrl(), 'active' => false]])),
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $account                    = $this->makeAccount();
        $account->webhook_id        = 'wh-1';
        $account->webhook_active    = true;
        $account->webhook_checked_at = now()->subHour();
        $account->save();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('Paused by Kapso', $html);
        $this->assertStringContainsString(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]), $html);

        $this->assertFalse($account->fresh()->webhook_active);
    }

    public function test_the_settings_page_does_not_refresh_a_fresh_status()
    {
        $this->fakeResponses([]);

        $account                             = $this->makeAccount();
        $account->webhook_id                 = 'wh-1';
        $account->webhook_active             = true;
        $account->webhook_checked_at         = now();
        $account->webhook_check_attempted_at = now();
        $account->save();

        $this->actingAs($this->adminUser())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $this->assertCount(0, $this->history);
    }

    /**
     * Kapso being unreachable must degrade to a message on the page, never to
     * an unusable settings screen.
     */
    public function test_the_settings_page_still_renders_when_kapso_is_unreachable()
    {
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'boom']))]);

        $account                     = $this->makeAccount();
        $account->webhook_id         = 'wh-1';
        $account->webhook_checked_at = now()->subHour();
        $account->save();

        $this->actingAs($this->adminUser())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $this->assertNotNull($account->fresh()->webhook_error);
    }

    public function test_an_unregistered_account_renders_a_register_button()
    {
        $this->fakeResponses([]);
        $account = $this->makeAccount();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString(route('kapsowhatsapp.webhook.register', ['id' => $account->id]), $html);
        $this->assertStringContainsString('Not registered', $html);
        $this->assertStringNotContainsString('curl', strtolower($html));
    }

    public function test_a_paused_account_renders_the_reason_and_a_resume_button()
    {
        $this->fakeResponses([]);

        $account                             = $this->makeAccount();
        $account->webhook_id                 = 'wh-1';
        $account->webhook_active             = false;
        $account->webhook_checked_at         = now();
        $account->webhook_check_attempted_at = now();
        $account->webhook_error              = 'Kapso has paused this webhook after failed deliveries.';
        $account->save();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]), $html);
        $this->assertStringContainsString('Kapso has paused this webhook after failed deliveries.', $html);
    }

    public function test_an_active_account_renders_as_active_with_no_resume_button()
    {
        $this->fakeResponses([]);

        $account                             = $this->makeAccount();
        $account->webhook_id                 = 'wh-1';
        $account->webhook_active             = true;
        $account->webhook_checked_at         = now();
        $account->webhook_check_attempted_at = now();
        $account->save();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString(route('kapsowhatsapp.webhook.resume', ['id' => $account->id]), $html);
        $this->assertStringContainsString(route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]), $html);
    }

    /**
     * A 204 No Content PATCH response (documented behaviour of register() and
     * resume()) leaves webhook_active null. The page must not present that as
     * "Active" -- it genuinely does not know -- but it must still offer the
     * same one-click way to find out.
     */
    public function test_an_account_with_unknown_webhook_status_is_not_shown_as_active()
    {
        $this->fakeResponses([]);

        $account                             = $this->makeAccount();
        $account->webhook_id                 = 'wh-1';
        $account->webhook_active             = null;
        $account->webhook_checked_at         = now();
        $account->webhook_check_attempted_at = now();
        $account->save();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('Active</span>', $html);
        $this->assertStringContainsString('Registered, status not confirmed', $html);
        $this->assertStringContainsString(route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]), $html);
    }

    public function test_a_webhook_registered_at_a_different_url_is_flagged()
    {
        $this->fakeResponses([]);

        $account                             = $this->makeAccount();
        $account->webhook_id                 = 'wh-1';
        $account->webhook_active             = true;
        $account->webhook_url                = 'https://old.example.com/kapso-whatsapp/webhook';
        $account->webhook_checked_at         = now();
        $account->webhook_check_attempted_at = now();
        $account->save();

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('https://old.example.com/kapso-whatsapp/webhook', $html);
        $this->assertStringContainsString('Register again', $html);
    }

    public function test_the_account_form_no_longer_asks_for_a_webhook_secret()
    {
        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('name="webhook_secret"', $html);
        $this->assertStringContainsString('generated', strtolower($html));
    }
}
