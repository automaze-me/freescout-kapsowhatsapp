<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
use Modules\KapsoWhatsApp\Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The API key is a module-wide setting, not a per-account attribute.
        Settings::setApiKey('secret-key');
    }

    protected function admin(): User
    {
        return $this->adminUser();
    }

    protected function nonAdmin(): User
    {
        return $this->regularUser();
    }

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    /**
     * store() resolves phone_number_id and business_account_id from Kapso's
     * own list (Task 3) rather than accepting either as free text, so any
     * test that reaches applyCreateRequest() needs a fake list containing the
     * id it posts. update() never contacts Kapso at all -- the number is
     * immutable after creation -- so update-path tests do not need this.
     */
    protected function numbersResponse(array $records): Response
    {
        return new Response(200, [], json_encode(['data' => $records]));
    }

    /**
     * store() now registers the new account's webhook right after saving it
     * (findOwnWebhook()'s list, then a create), so any test whose fake queue
     * reaches applyCreateRequest() successfully needs these two more queued
     * after its numbersResponse().
     */
    protected function webhookRegistrationResponses(string $webhookId = 'wh-created'): array
    {
        $url = WebhookRegistrar::webhookUrl();

        return [
            new Response(200, [], json_encode(['data' => []])),
            new Response(201, [], json_encode(['data' => ['id' => $webhookId, 'active' => true, 'url' => $url]])),
        ];
    }

    /**
     * webhook_secret is intentionally not $fillable, so fixtures set it via
     * direct property assignment, same as DataModelTest.
     */
    protected function makeAccount(array $overrides = []): KapsoAccount
    {
        $mailboxId = $overrides['mailbox_id'] ?? $this->testMailbox()->id;

        $account = new KapsoAccount();
        $account->fill(array_merge([
            'name'                => 'Support',
            'phone_number_id'     => '1'.uniqid(),
            'business_account_id' => '999',
            'mailbox_id'          => $mailboxId,
            'is_active'           => true,
        ], $overrides));
        $account->webhook_secret = $overrides['webhook_secret'] ?? 'secret-hmac';
        $account->save();

        return $account;
    }

    public function test_admin_can_view_the_settings_page()
    {
        $this->actingAs($this->admin())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200);
    }

    public function test_non_admin_is_denied()
    {
        $this->actingAs($this->nonAdmin())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login()
    {
        $this->get(route('kapsowhatsapp.settings'))->assertStatus(302);
    }

    /**
     * The manual "Register with Kapso" step is gone: creating an account now
     * registers its webhook automatically as part of store().
     */
    public function test_admin_can_create_an_account()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses(array_merge(
            [$this->numbersResponse([
                ['phone_number_id' => '123456789012345', 'business_account_id' => 'waba-1', 'display_phone_number' => '+49 177 5550000'],
            ])],
            $this->webhookRegistrationResponses('wh-created-1')
        ));

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ]);

        $response->assertStatus(302)->assertSessionHas('flash_success_floating');
        $this->assertStringContainsString('Webhook registered', session('flash_success_floating'));

        $account = KapsoAccount::where('phone_number_id', '123456789012345')->first();
        $this->assertNotNull($account);
        $this->assertSame($mailbox->id, (int) $account->mailbox_id);
        $this->assertSame('wh-created-1', $account->webhook_id, 'creating an account must register its webhook automatically');
        // User feedback: the Phone Number ID means nothing to a human, so
        // the record's display_phone_number is stored for the admin UI.
        $this->assertSame('+49 177 5550000', $account->phone_number);
        $this->assertSame('+49 177 5550000', $account->display_number);
    }

    /**
     * Accounts created before phone_number existed (or whose Kapso record
     * had no display_phone_number yet) must keep showing SOMETHING that
     * identifies them -- the id is the graceful fallback, never a blank.
     */
    public function test_display_number_falls_back_to_the_phone_number_id()
    {
        $account = $this->makeAccount();

        $this->assertNull($account->phone_number);
        $this->assertSame($account->phone_number_id, $account->display_number);

        $account->phone_number = '+49 177 5550001';
        $this->assertSame('+49 177 5550001', $account->display_number);
    }

    /**
     * A Kapso outage at registration time must not lose the account: it is
     * already saved by the time register() is attempted, and the row must
     * say why the webhook isn't registered yet rather than the create
     * silently failing or 500ing.
     */
    public function test_creating_an_account_while_kapso_is_down_still_saves_it()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses([
            $this->numbersResponse([
                ['phone_number_id' => '123456789099999', 'business_account_id' => 'waba-1'],
            ]),
            new Response(500, [], json_encode(['error' => 'boom'])), // register()'s own list call fails
        ]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789099999',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ]);

        $response->assertStatus(302)->assertSessionHas('flash_error_floating');
        $this->assertStringContainsString('retried automatically', session('flash_error_floating'));

        $account = KapsoAccount::where('phone_number_id', '123456789099999')->first();
        $this->assertNotNull($account, 'the account must be saved even though registration failed');
        $this->assertNull($account->webhook_id);
        $this->assertNotNull($account->webhook_error);
        $this->assertNotNull($account->webhook_check_attempted_at, 'a failed attempt must still stamp the throttle');
        $attemptedAt   = $account->webhook_check_attempted_at->timestamp;
        $recordedError = $account->webhook_error;

        // The settings page must still render afterwards, and the attempt
        // just stamped above must stop it from immediately retrying Kapso.
        // An empty mock queue alone cannot prove that on its own: an
        // attempted-but-throttled-away call and no call at all both leave
        // $this->history empty, because platformRequest() rewraps the empty
        // queue's OutOfBoundsException into a KapsoApiException before
        // Guzzle's history middleware ever sees it. attempted_at/webhook_error
        // staying exactly as stamped above is what actually proves it.
        $this->fakeResponses([]);
        $this->actingAs($this->admin())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $account = $account->fresh();
        $this->assertSame($attemptedAt, $account->webhook_check_attempted_at->timestamp, 'a call inside the throttle window must not restamp the attempt time');
        $this->assertSame($recordedError, $account->webhook_error, 'a call inside the throttle window must not overwrite the recorded error');
        $this->assertCount(0, $this->history, 'a check attempted moments ago must not be retried immediately');
    }

    /**
     * store() must not register the webhook for an account created inactive.
     * KapsoAccount::findByPhoneNumberId() only matches active accounts, so a
     * webhook registered against one would have every delivery rejected 403
     * by the signature middleware -- and ~15 minutes of that is exactly what
     * makes Kapso auto-pause it on its own. webhook_check_attempted_at must
     * also stay null on this path: that is what lets the settings-page loop
     * (reconcileWebhook()) pick the account up the instant it is activated,
     * instead of it already looking "registered" (paused) with nothing left
     * to self-heal.
     */
    public function test_creating_an_inactive_account_does_not_register_its_webhook()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses([
            $this->numbersResponse([
                ['phone_number_id' => '123456789077777', 'business_account_id' => 'waba-1'],
            ]),
        ]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789077777',
            'mailbox_id'      => $mailbox->id,
            // is_active intentionally absent, as an unchecked checkbox posts nothing.
        ]);

        $response->assertStatus(302)->assertSessionHas('flash_success_floating');
        $this->assertStringNotContainsString('registered', session('flash_success_floating'), 'no registration was attempted, so nothing should claim one happened');

        $account = KapsoAccount::where('phone_number_id', '123456789077777')->first();
        $this->assertNotNull($account, 'the account must still be saved');
        $this->assertFalse((bool) $account->is_active);
        $this->assertNull($account->webhook_id);
        $this->assertNull($account->webhook_check_attempted_at, 'leaving this null is what lets activation self-heal via the settings-page loop');
        $this->assertCount(1, $this->history, 'only the numbers list -- no registration attempt for an inactive account');

        // Activating it via update() must not itself register anything --
        // update() never contacts Kapso at all. It is the *next
        // settings-page load* that heals it, exactly the way any other
        // stale/unregistered active account would be.
        $this->fakeResponses([]);
        $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'       => 'Support',
            'mailbox_id' => $mailbox->id,
            'is_active'  => 1,
        ])->assertStatus(302);

        $this->assertTrue((bool) $account->fresh()->is_active);
        $this->assertNull($account->fresh()->webhook_id, 'update() itself must not register anything');
        $this->assertCount(0, $this->history, 'update() must never contact Kapso');

        // The next settings-page load auto-registers it now that it is active.
        $this->fakeResponses($this->webhookRegistrationResponses('wh-activated'));
        $this->actingAs($this->admin())->get(route('kapsowhatsapp.settings'))->assertStatus(200);

        $this->assertSame('wh-activated', $account->fresh()->webhook_id, 'activating a previously-inactive account must self-heal on the very next settings-page load');
    }

    public function test_phone_number_id_must_be_unique()
    {
        $admin   = $this->admin();
        $mailbox = $this->testMailbox();
        $payload = [
            'name'            => 'Support',
            'phone_number_id' => '555000111',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ];

        // Only the first POST reaches Kapso: the second is rejected by the
        // uniqueness rule before applyCreateRequest() ever calls availableNumbers().
        $this->fakeResponses(array_merge(
            [$this->numbersResponse([
                ['phone_number_id' => '555000111', 'business_account_id' => 'waba-1'],
            ])],
            $this->webhookRegistrationResponses('wh-created-2')
        ));

        $this->actingAs($admin)->post(route('kapsowhatsapp.store'), $payload);
        $this->actingAs($admin)->post(route('kapsowhatsapp.store'), $payload)
            ->assertSessionHasErrors('phone_number_id');

        $this->assertSame(1, KapsoAccount::where('phone_number_id', '555000111')->count());
    }

    // --- Authorization: edit ---

    public function test_non_admin_is_denied_edit()
    {
        $account = $this->makeAccount();

        $this->actingAs($this->nonAdmin())
            ->get(route('kapsowhatsapp.edit', ['id' => $account->id]))
            ->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login_on_edit()
    {
        $account = $this->makeAccount();

        $this->get(route('kapsowhatsapp.edit', ['id' => $account->id]))
            ->assertStatus(302);
    }

    // --- Authorization: update ---

    public function test_non_admin_is_denied_update()
    {
        $account = $this->makeAccount();

        $this->actingAs($this->nonAdmin())
            ->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
                'name'            => 'Changed',
                'phone_number_id' => $account->phone_number_id,
                'mailbox_id'      => $account->mailbox_id,
            ])
            ->assertStatus(403);

        $this->assertSame('Support', $account->fresh()->name);
    }

    public function test_guest_is_redirected_to_login_on_update()
    {
        $account = $this->makeAccount();

        $this->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => 'Changed',
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
        ])->assertStatus(302);

        $this->assertSame('Support', $account->fresh()->name);
    }

    // --- Authorization: destroy ---

    public function test_non_admin_is_denied_destroy()
    {
        $account = $this->makeAccount();

        $this->actingAs($this->nonAdmin())
            ->post(route('kapsowhatsapp.destroy', ['id' => $account->id]))
            ->assertStatus(403);

        $this->assertNotNull(KapsoAccount::find($account->id));
    }

    public function test_guest_is_redirected_to_login_on_destroy()
    {
        $account = $this->makeAccount();

        $this->post(route('kapsowhatsapp.destroy', ['id' => $account->id]))
            ->assertStatus(302);

        $this->assertNotNull(KapsoAccount::find($account->id));
    }

    // --- Blank vs. non-blank secrets on update ---

    /**
     * The webhook secret is no longer an admin-supplied value: it is minted by
     * WebhookRegistrar at registration time so FreeScout and Kapso can never
     * hold different secrets. A secret posted to the form must be ignored.
     */
    public function test_a_webhook_secret_posted_to_the_form_is_ignored()
    {
        $account = $this->makeAccount(['webhook_secret' => 'original-webhook-secret']);
        $this->fakeResponses([]);

        $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => $account->name,
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
            'webhook_secret'  => 'attacker-supplied',
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('original-webhook-secret', $account->fresh()->webhook_secret);
    }

    /**
     * The API key is a module-wide setting (Task 1), not a per-account value,
     * and the account form has had no api_key field at all since then -- so
     * an api_key posted alongside an otherwise-ordinary update must not touch
     * it either, the same rule already enforced above for webhook_secret.
     */
    public function test_an_api_key_posted_to_the_form_is_ignored()
    {
        $account = $this->makeAccount();
        $this->fakeResponses([]);

        $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => $account->name,
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
            'api_key'         => 'attacker-supplied',
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('secret-key', Settings::apiKey(), 'posting api_key on the per-account form must not touch the module-wide key');
    }

    /**
     * webhook_secret has never been an admin-supplied form field -- store()
     * does not read it from the request at all. Before automatic
     * registration this meant a freshly created account simply had no
     * secret yet; now register() mints one immediately as part of create,
     * so what this asserts is that the secret came from that registration,
     * never from anything posted (there is nothing to post it in).
     */
    public function test_an_account_can_be_created_with_no_webhook_secret_in_the_request()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses(array_merge(
            [$this->numbersResponse([
                ['phone_number_id' => '123456789012399', 'business_account_id' => 'waba-1'],
            ])],
            $this->webhookRegistrationResponses('wh-created-3')
        ));

        $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789012399',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ])->assertStatus(302)->assertSessionMissing('errors');

        $account = KapsoAccount::where('phone_number_id', '123456789012399')->first();
        $this->assertNotNull($account);
        $this->assertNotNull($account->webhook_secret, 'the secret comes from automatic registration, not from the request');
    }

    // --- destroy ---

    public function test_admin_can_destroy_an_account()
    {
        $account = $this->makeAccount();

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.destroy', ['id' => $account->id]));

        $response->assertRedirect(route('kapsowhatsapp.settings'));
        $this->assertNull(KapsoAccount::find($account->id));
    }

    // --- Update: the number is immutable ---

    public function test_update_can_rename_an_account()
    {
        $account = $this->makeAccount(['phone_number_id' => '700000000000001']);
        $this->fakeResponses([]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => 'Renamed Again',
            'phone_number_id' => '700000000000001',
            'mailbox_id'      => $account->mailbox_id,
            'is_active'       => 1,
        ]);

        $response->assertRedirect(route('kapsowhatsapp.settings'));
        $this->assertSame('Renamed Again', $account->fresh()->name);
        $this->assertSame('700000000000001', $account->fresh()->phone_number_id);
        $this->assertCount(0, $this->history, 'update() must never contact Kapso');
    }

    /**
     * Replaces the old uniqueness-on-update test: with phone_number_id
     * no longer read from the request at all, posting another account's
     * number id is not "rejected" -- there is no validation left to reject
     * it. It just changes nothing, the same as posting any other value would.
     */
    public function test_posting_another_accounts_phone_number_id_on_update_changes_nothing()
    {
        $accountA = $this->makeAccount(['phone_number_id' => '700000000000002']);
        $accountB = $this->makeAccount(['phone_number_id' => '700000000000003']);

        // Install an empty fake queue rather than none at all: with no fake
        // installed, an unexpected call would silently fall back to a real
        // Guzzle client and could hang on a genuine outbound request while
        // still passing -- an empty queue makes that regression throw instead.
        $this->fakeResponses([]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $accountA->id]), [
            'name'            => $accountA->name,
            'phone_number_id' => $accountB->phone_number_id,
            'mailbox_id'      => $accountA->mailbox_id,
            'is_active'       => 1,
        ]);

        $response->assertStatus(302)->assertSessionMissing('errors');
        $this->assertSame('700000000000002', $accountA->fresh()->phone_number_id);
        $this->assertCount(0, $this->history);
    }
}
