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
}
