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
     * an auto-pause visible without anyone clicking anything.
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

        $this->actingAs($this->adminUser())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $this->assertFalse($account->fresh()->webhook_active);
    }

    public function test_the_settings_page_does_not_refresh_a_fresh_status()
    {
        $this->fakeResponses([]);

        $account                     = $this->makeAccount();
        $account->webhook_id         = 'wh-1';
        $account->webhook_active     = true;
        $account->webhook_checked_at = now();
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
}
