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
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;
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
        $this->fakeResponses(array_merge(
            [$this->numbersResponse($this->twoNumbers())],
            $this->webhookRegistrationResponses('wh-np-1')
        ));

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

    public function test_a_blank_name_is_filled_in_from_the_selected_number()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses(array_merge(
            [$this->numbersResponse($this->twoNumbers())],
            $this->webhookRegistrationResponses('wh-np-2')
        ));

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'            => '',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('Acme GmbH', KapsoAccount::where('phone_number_id', '111')->first()->name);
    }

    /**
     * The last-resort name fallback must not bake in a moment-in-time
     * quality rating: label() appends " (RED)"/" (YELLOW)" for a dropdown
     * row that is rebuilt fresh from Kapso on every page load, but a stored
     * account name is not a dropdown row -- a recovered rating must not
     * leave the account permanently misnamed.
     */
    public function test_a_blank_name_falls_back_to_the_number_not_a_quality_rating()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses(array_merge(
            [$this->numbersResponse([
                ['phone_number_id' => '333', 'business_account_id' => 'waba-3', 'display_phone_number' => '+49 151 3', 'quality_rating' => 'RED'],
            ])],
            $this->webhookRegistrationResponses('wh-np-3')
        ));

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'            => '',
            'phone_number_id' => '333',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('+49 151 3', KapsoAccount::where('phone_number_id', '333')->first()->name);
    }

    protected function sessionErrorMessage($field)
    {
        return app('session.store')->get('errors')->getBag('default')->first($field);
    }

    /**
     * A Kapso outage on save must not be reported as if the submitted number
     * were invalid: an admin who knows perfectly well the number is right
     * reads "that number is not in your project" as data corruption. Nothing
     * is written either way -- this stays fail-closed regardless of which
     * message is shown.
     */
    public function test_a_kapso_outage_on_create_reports_the_outage_not_a_bad_number()
    {
        Settings::setApiKey('key-abc');
        $this->fakeResponses([new Response(500, [], json_encode(['error' => 'boom']))]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.store'), [
            'name'            => 'Support',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => 1,
        ])->assertStatus(302)->assertSessionHasErrors('phone_number_id');

        $message = $this->sessionErrorMessage('phone_number_id');

        $this->assertStringContainsString('Kapso', $message);
        $this->assertStringNotContainsString('is not one of the WhatsApp numbers', $message);
        $this->assertNull(KapsoAccount::where('phone_number_id', '111')->first());
    }

    /**
     * update() no longer calls Kapso at all -- the number is immutable after
     * creation, so there is nothing left to look up. The edit page must
     * therefore render with zero outbound HTTP, whether Kapso is up, down or
     * slow: an empty fake queue makes any accidental call throw loudly
     * instead of silently reaching a real Guzzle client.
     */
    public function test_the_edit_page_renders_during_a_kapso_outage()
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

        $this->fakeResponses([]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.edit', ['id' => $account->id]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('111', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="mailbox_id"', $html);
        $this->assertStringContainsString('name="is_active"', $html);
        $this->assertStringNotContainsString('<select name="phone_number_id"', $html);
        $this->assertCount(0, $this->history, 'the edit page must not make any outbound call');
    }

    /**
     * This is the test that captures why the user wanted this: during a
     * Kapso outage an admin must still be able to deactivate an account --
     * that is exactly the moment they most need to.
     */
    public function test_an_account_can_be_deactivated_during_an_outage()
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

        $this->fakeResponses([]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'       => 'Existing',
            'mailbox_id' => $account->mailbox_id,
            // is_active intentionally absent, as an unchecked checkbox posts nothing.
        ])->assertStatus(302);

        $this->assertFalse((bool) $account->fresh()->is_active);
        $this->assertCount(0, $this->history, 'deactivating must not depend on Kapso being reachable');
    }

    /**
     * The number is the account's identity once created. A tampered or
     * merely stale posted value must be ignored entirely -- not looked up,
     * not validated, not even read -- so this asserts zero HTTP requests too:
     * update() has nothing to check the posted id against any more.
     *
     * The fake queue below deliberately answers with a record for the
     * *posted* number ('222'), not the account's own: were update() still
     * looking the posted id up (the old contract), this response would let
     * it succeed and apply the tamper, so this is a genuine test of the new
     * contract rather than one that only happens to pass either way.
     */
    public function test_a_posted_phone_number_id_on_update_is_ignored_entirely()
    {
        Settings::setApiKey('key-abc');

        $account = new KapsoAccount();
        $account->fill([
            'name'                => 'Existing',
            'phone_number_id'     => '111',
            'business_account_id' => 'waba-1',
            'mailbox_id'          => $this->testMailbox()->id,
            'is_active'           => true,
        ]);
        $account->save();

        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '222', 'business_account_id' => 'waba-TAMPERED'],
        ])]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'                => 'Existing',
            'phone_number_id'     => '222',
            'business_account_id' => 'waba-TAMPERED',
            'mailbox_id'          => $account->mailbox_id,
            'is_active'           => 1,
        ])->assertStatus(302);

        $fresh = $account->fresh();
        $this->assertSame('111', $fresh->phone_number_id);
        $this->assertSame('waba-1', $fresh->business_account_id);
        $this->assertCount(0, $this->history, 'update() must not look the posted id up at all');
    }

    /**
     * update()'s blank-name rule is simpler than create's: there is no
     * Kapso record to re-derive a name from any more, so blank just means
     * "leave the existing name alone". The fake queue answers with a
     * *different* name for the account's own number: were update() still
     * re-deriving a blank name from Kapso (the old, create-only contract),
     * the account would end up renamed to it instead of keeping its name.
     */
    public function test_a_blank_name_on_update_keeps_the_existing_name()
    {
        Settings::setApiKey('key-abc');

        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Existing Name',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->save();

        $this->fakeResponses([$this->numbersResponse([
            ['phone_number_id' => '111', 'verified_name' => 'Kapso-Derived Name'],
        ])]);

        $this->actingAs($this->adminUser())->post(route('kapsowhatsapp.update', ['id' => $account->id]), [
            'name'            => '',
            // Inert on the current contract -- update() never reads this
            // field. Posted anyway so the test doubles as proof: were update()
            // to regress into looking it up, the fake response above would be
            // consumed and the zero-HTTP assertion below would catch it.
            'phone_number_id' => '111',
            'mailbox_id'      => $account->mailbox_id,
            'is_active'       => 1,
        ])->assertStatus(302);

        $this->assertSame('Existing Name', $account->fresh()->name);
        $this->assertCount(0, $this->history, 'update() must not consult Kapso even to fill in a blank name');
    }

    public function test_an_admin_can_save_the_api_key()
    {
        $this->fakeResponses([]);

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => 'key-from-form'])
            ->assertStatus(302)
            ->assertSessionHas('flash_success_floating');

        $this->assertSame('key-from-form', Settings::apiKey());
    }

    public function test_a_blank_api_key_leaves_the_stored_one_alone()
    {
        Settings::setApiKey('existing-key');
        $this->fakeResponses([]);

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => ''])
            ->assertStatus(302);

        $this->assertSame('existing-key', Settings::apiKey());
    }

    public function test_a_whitespace_only_api_key_leaves_the_stored_one_alone()
    {
        Settings::setApiKey('existing-key');
        $this->fakeResponses([]);

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => '   '])
            ->assertStatus(302);

        $this->assertSame('existing-key', Settings::apiKey());
    }

    /**
     * saveApiKey() deliberately avoids validate(): a failed validation
     * flashes the submitted input into the session for old(), and a secret
     * must never land in the session store even though nothing renders it.
     */
    public function test_an_oversized_api_key_is_rejected_without_entering_the_session()
    {
        Settings::setApiKey('existing-key');
        $this->fakeResponses([]);

        $huge = str_repeat('k', 600);

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => $huge])
            ->assertStatus(302)
            ->assertSessionHas('flash_error_floating')
            ->assertSessionMissing('_old_input');

        $this->assertSame('existing-key', Settings::apiKey());
    }

    public function test_a_non_admin_cannot_save_the_api_key()
    {
        Settings::setApiKey('existing-key');
        $this->fakeResponses([]);

        $this->actingAs($this->regularUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => 'attacker-key'])
            ->assertStatus(403);

        $this->assertSame('existing-key', Settings::apiKey());
    }

    /**
     * The most common registration failure is a bad/missing key. Once the
     * admin actually saves a new one, every account's throttle is cleared so
     * the very next settings-page load registers/heals them immediately
     * instead of making the admin wait out the 5-minute stale window.
     */
    public function test_saving_a_key_nulls_the_registration_throttle_on_existing_accounts()
    {
        $this->fakeResponses([]);

        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Existing',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->webhook_check_attempted_at = now();
        $account->save();

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => 'fresh-key'])
            ->assertStatus(302);

        $this->assertNull($account->fresh()->webhook_check_attempted_at, 'a newly saved key must un-throttle every account so the next load retries them right away');
    }

    /**
     * A blank submission ("leave unchanged") must not have this side effect
     * -- only a key that is actually saved un-throttles anything.
     */
    public function test_a_blank_api_key_submission_does_not_touch_the_throttle()
    {
        Settings::setApiKey('existing-key');
        $this->fakeResponses([]);

        $account = new KapsoAccount();
        $account->fill([
            'name'            => 'Existing',
            'phone_number_id' => '111',
            'mailbox_id'      => $this->testMailbox()->id,
            'is_active'       => true,
        ]);
        $account->webhook_check_attempted_at = now();
        $account->save();
        $stampedAt = $account->webhook_check_attempted_at->timestamp;

        $this->actingAs($this->adminUser())
            ->post(route('kapsowhatsapp.apikey'), ['api_key' => ''])
            ->assertStatus(302);

        $this->assertSame($stampedAt, $account->fresh()->webhook_check_attempted_at->timestamp);
    }

    public function test_the_settings_page_never_renders_the_stored_key()
    {
        Settings::setApiKey('super-secret-key');
        $this->fakeResponses([]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('super-secret-key', $html);
    }

    public function test_the_settings_page_prompts_for_a_key_when_none_is_set()
    {
        $this->fakeResponses([]);

        $html = $this->actingAs($this->adminUser())
            ->get(route('kapsowhatsapp.settings'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('API key', $html);
        $this->assertStringNotContainsString('curl', strtolower($html));
    }
}
