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
     * store()/update() now resolve phone_number_id and business_account_id
     * from Kapso's own list (Task 3) rather than accepting either as free
     * text, so any test that reaches applyRequest() needs a fake list
     * containing the id it posts.
     */
    protected function numbersResponse(array $records): Response
    {
        return new Response(200, [], json_encode(['data' => $records]));
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

    public function test_admin_can_create_an_account()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '123456789012345', 'business_account_id' => 'waba-1'],
        ])]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ]);

        $response->assertStatus(302);

        $account = KapsoAccount::where('phone_number_id', '123456789012345')->first();
        $this->assertNotNull($account);
        $this->assertSame($mailbox->id, (int) $account->mailbox_id);
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
        // uniqueness rule before applyRequest() ever calls availableNumbers().
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '555000111', 'business_account_id' => 'waba-1'],
        ])]);

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
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => $account->phone_number_id, 'business_account_id' => 'waba-1'],
        ])]);

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
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => $account->phone_number_id, 'business_account_id' => 'waba-1'],
        ])]);

        $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => $account->name,
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
            'api_key'         => 'attacker-supplied',
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('secret-key', Settings::apiKey(), 'posting api_key on the per-account form must not touch the module-wide key');
    }

    public function test_an_account_can_be_created_without_a_webhook_secret()
    {
        $mailbox = $this->testMailbox();
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '123456789012399', 'business_account_id' => 'waba-1'],
        ])]);

        $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789012399',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ])->assertStatus(302)->assertSessionMissing('errors');

        $account = KapsoAccount::where('phone_number_id', '123456789012399')->first();
        $this->assertNotNull($account);
        $this->assertNull($account->webhook_secret);
    }

    // --- destroy ---

    public function test_admin_can_destroy_an_account()
    {
        $account = $this->makeAccount();

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.destroy', ['id' => $account->id]));

        $response->assertRedirect(route('kapsowhatsapp.settings'));
        $this->assertNull(KapsoAccount::find($account->id));
    }

    // --- Update validation: unique phone_number_id excludes the current row ---

    public function test_update_allows_keeping_its_own_phone_number_id()
    {
        $account = $this->makeAccount(['phone_number_id' => '700000000000001']);
        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '700000000000001', 'business_account_id' => 'waba-1'],
        ])]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => 'Renamed Again',
            'phone_number_id' => '700000000000001',
            'mailbox_id'      => $account->mailbox_id,
            'is_active'       => 1,
        ]);

        $response->assertRedirect(route('kapsowhatsapp.settings'));
        $this->assertSame('Renamed Again', $account->fresh()->name);
    }

    public function test_update_rejects_another_accounts_phone_number_id()
    {
        $accountA = $this->makeAccount(['phone_number_id' => '700000000000002']);
        $accountB = $this->makeAccount(['phone_number_id' => '700000000000003']);

        // The uniqueness rule rejects this before applyRequest() ever calls
        // availableNumbers(), so no Kapso call is expected here.
        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $accountA->id]), [
            'name'            => $accountA->name,
            'phone_number_id' => $accountB->phone_number_id,
            'mailbox_id'      => $accountA->mailbox_id,
            'is_active'       => 1,
        ]);

        $response->assertSessionHasErrors('phone_number_id');
        $this->assertSame('700000000000002', $accountA->fresh()->phone_number_id);
    }
}
