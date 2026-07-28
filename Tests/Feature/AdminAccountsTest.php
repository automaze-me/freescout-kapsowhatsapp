<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\User;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    protected function admin(): User
    {
        return $this->adminUser();
    }

    protected function nonAdmin(): User
    {
        return $this->regularUser();
    }

    /**
     * api_key/webhook_secret are intentionally not $fillable (Task 2), so
     * fixtures set them via direct property assignment, same as DataModelTest.
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
        $account->api_key        = $overrides['api_key'] ?? 'secret-key';
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

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '123456789012345',
            'api_key'         => 'key-abc',
            'webhook_secret'  => 'hmac-abc',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ]);

        $response->assertStatus(302);

        $account = KapsoAccount::where('phone_number_id', '123456789012345')->first();
        $this->assertNotNull($account);
        $this->assertSame('key-abc', $account->api_key);
        $this->assertSame($mailbox->id, (int) $account->mailbox_id);
    }

    public function test_phone_number_id_must_be_unique()
    {
        $admin = $this->admin();
        $mailbox = $this->testMailbox();
        $payload = [
            'name'            => 'Support',
            'phone_number_id' => '555000111',
            'api_key'         => 'key',
            'webhook_secret'  => 'hmac',
            'mailbox_id'      => $mailbox->id,
            'is_active'       => 1,
        ];

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

    public function test_update_with_blank_secrets_leaves_stored_secrets_unchanged()
    {
        $account = $this->makeAccount([
            'api_key'        => 'original-api-key',
            'webhook_secret' => 'original-webhook-secret',
        ]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => 'Renamed Support',
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
            'api_key'         => '',
            'webhook_secret'  => '',
            'is_active'       => 1,
        ]);

        $response->assertRedirect(route('kapsowhatsapp.settings'));

        $fresh = $account->fresh();
        $this->assertSame('Renamed Support', $fresh->name, 'the non-secret field should still have been updated');
        $this->assertSame('original-api-key', $fresh->api_key);
        $this->assertSame('original-webhook-secret', $fresh->webhook_secret);
    }

    public function test_update_with_non_blank_secrets_replaces_stored_secrets()
    {
        $account = $this->makeAccount([
            'api_key'        => 'original-api-key',
            'webhook_secret' => 'original-webhook-secret',
        ]);

        $response = $this->actingAs($this->admin())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => $account->name,
            'phone_number_id' => $account->phone_number_id,
            'mailbox_id'      => $account->mailbox_id,
            'api_key'         => 'new-api-key',
            'webhook_secret'  => 'new-webhook-secret',
            'is_active'       => 1,
        ]);

        $response->assertRedirect(route('kapsowhatsapp.settings'));

        $fresh = $account->fresh();
        $this->assertSame('new-api-key', $fresh->api_key);
        $this->assertSame('new-webhook-secret', $fresh->webhook_secret);
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
