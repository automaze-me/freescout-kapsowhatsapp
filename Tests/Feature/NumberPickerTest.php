<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Tests\TestCase;

class NumberPickerTest extends TestCase
{
    protected $history = [];

    protected function fakeResponses(array $queue): void
    {
        $this->history = [];
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        KapsoClient::fakeHttp(new Client(['handler' => $stack]));
    }

    protected function numbersResponse(array $records): Response
    {
        return new Response(200, [], json_encode(['data' => $records]));
    }

    protected function twoNumbers(): array
    {
        return [
            ['phone_number_id' => '111', 'business_account_id' => 'waba-1', 'display_phone_number' => '+49 151 1', 'verified_name' => 'Acme GmbH'],
            ['phone_number_id' => '222', 'business_account_id' => 'waba-2', 'display_phone_number' => '+49 151 2', 'verified_name' => 'Acme Support'],
        ];
    }

    public function test_the_form_renders_a_dropdown_of_the_projects_numbers()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('Acme GmbH', $html);
        $this->assertStringContainsString('+49 151 1', $html);
        $this->assertStringContainsString('value="111"', $html);

        $this->assertStringNotContainsString('name="business_account_id"', $html, 'the WABA id is never typed by a human');
        $this->assertStringNotContainsString('name="api_key"', $html, 'the API key is an install-wide setting now');
    }

    public function test_saving_takes_both_ids_from_kapso_not_from_the_form()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $mailbox = $this->testMailbox();

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'                => 'Support',
            'phone_number_id'     => '222',
            'business_account_id' => 'waba-TAMPERED',
            'mailbox_id'          => $mailbox->id,
            'is_active'           => 1,
        ])->assertStatus(302)->assertSessionMissing('errors');

        $account = KapsoAccount::where('phone_number_id', '222')->first();
        $this->assertNotNull($account);
        $this->assertSame('waba-2', $account->business_account_id, 'the WABA id must come from Kapso, never from the request');
    }

    public function test_a_number_kapso_does_not_know_is_rejected()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '999',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => 1,
        ])->assertStatus(302)->assertSessionHasErrors('phone_number_id');

        $this->assertNull(KapsoAccount::where('phone_number_id', '999')->first());
    }

    public function test_without_an_api_key_the_form_says_so_and_offers_no_id_fields()
    {
        $this->fakeResponses([]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('API key', $html);
        $this->assertStringNotContainsString('name="phone_number_id"', $html);
        $this->assertCount(0, $this->history, 'no Kapso call may be attempted without a key');
    }

    public function test_a_rejected_api_key_is_reported_on_the_form()
    {
        Settings::setApiKey('bad-key');
        $this->fakeResponses([new Response(401, [], json_encode(['error' => 'Invalid API key']))]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('API key', $html);
        $this->assertStringNotContainsString('curl', strtolower($html));
    }

    public function test_a_project_with_no_numbers_says_so(): void
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([$this->numbersResponse([])]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('no WhatsApp numbers', $html);
    }

    /**
     * phone_number_id is unique in this module's table, so a number already
     * bound to another account must not look pickable.
     */
    public function test_a_number_already_used_by_another_account_is_marked()
    {
        Settings::setApiKey('key-abc');

        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Existing',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->save();

        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.create'))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression('/<option[^>]*value="111"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<option[^>]*value="222"(?![^>]*disabled)/', $html);
    }

    public function test_editing_keeps_the_accounts_own_number_selectable()
    {
        Settings::setApiKey('key-abc');

        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Existing',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->save();

        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.edit', ['id' => $account->id]))
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression('/<option[^>]*value="111"[^>]*selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="111"[^>]*disabled/', $html);
    }

    public function test_a_blank_name_is_filled_in_from_the_selected_number()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([$this->numbersResponse($this->twoNumbers())]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'            => '',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('Acme GmbH', KapsoAccount::where('phone_number_id', '111')->first()->name);
    }
}
