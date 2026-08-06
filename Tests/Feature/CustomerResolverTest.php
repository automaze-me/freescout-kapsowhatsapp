<?php

namespace Modules\KapsoWhatsApp\Tests\Feature;

use App\Customer;
use App\CustomerChannel;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Services\CustomerResolver;
use Modules\KapsoWhatsApp\Tests\TestCase;

class CustomerResolverTest extends TestCase
{
    public function test_existing_channel_identity_wins()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Anna', 'last_name' => 'Weber']);
        $customer->addChannel(KapsoAccount::CHANNEL, '+4915100000001');

        $resolved = (new CustomerResolver())->resolve('+4915100000001', 'Ignored Name');

        $this->assertSame($customer->id, $resolved->id);
    }

    public function test_unique_phone_match_links_the_existing_customer()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Bernd', 'last_name' => 'Klein']);
        $customer->setPhones([['value' => '+4915100000002', 'type' => 1]]);
        $customer->save();

        $resolved = (new CustomerResolver())->resolve('+4915100000002', null);

        $this->assertSame($customer->id, $resolved->id);
        $this->assertSame('+4915100000002', $resolved->getChannelId(KapsoAccount::CHANNEL));
    }

    public function test_unique_phone_match_finds_a_customer_stored_in_national_format()
    {
        // The ordinary way a German customer's number gets typed into
        // FreeScout: national format with a leading trunk zero, not E.164.
        // Helper::phoneToNumeric() preserves that leading zero and never
        // substitutes a country code, so the prefilter must search on the
        // national significant number, not the full country-code-prefixed
        // digit string, or this customer is invisible to it. This depends on
        // the installation's configured country code (no global default any
        // more — see PhoneNumber), so this test passes it explicitly.
        $customer = Customer::createWithoutEmail(['first_name' => 'Frieda', 'last_name' => 'National']);
        $customer->setPhones([['value' => '0151 12345678', 'type' => 1]]);
        $customer->save();

        $resolved = (new CustomerResolver('49'))->resolve('+4915112345678', null);

        $this->assertSame($customer->id, $resolved->id);
        $this->assertSame('+4915112345678', $resolved->getChannelId(KapsoAccount::CHANNEL));
        $this->assertSame(1, Customer::where('first_name', 'Frieda')->count(),
            'must link to the existing customer rather than creating a duplicate');
    }

    public function test_ambiguous_phone_creates_a_new_customer_rather_than_guessing()
    {
        $existingIds = [];

        foreach (['Carla', 'Clara'] as $name) {
            $c = Customer::createWithoutEmail(['first_name' => $name, 'last_name' => 'Doppel']);
            $c->setPhones([['value' => '+4915100000003', 'type' => 1]]);
            $c->save();
            $existingIds[] = $c->id;
        }

        $resolved = (new CustomerResolver())->resolve('+4915100000003', 'Carla Doppel');

        // "Not linked to either candidate" means a distinct customer row, not
        // a distinct name: the new customer legitimately inherits the WhatsApp
        // contact name, which may coincide with one of the ambiguous
        // candidates' names (as it does here, by design, to prove that a name
        // collision alone must never cause a link).
        $this->assertNotContains($resolved->id, $existingIds,
            'an ambiguous match must not be linked to either candidate');
        $this->assertSame('+4915100000003', $resolved->getChannelId(KapsoAccount::CHANNEL));
    }

    public function test_unknown_number_creates_an_email_less_customer_named_from_whatsapp_profile()
    {
        $resolved = (new CustomerResolver())->resolve('+4915100000004', 'Dora Neu');

        $this->assertSame('Dora', $resolved->first_name);
        $this->assertSame('Neu', $resolved->last_name);
        $this->assertSame('', $resolved->getMainEmail());
        $this->assertSame('+4915100000004', $resolved->getChannelId(KapsoAccount::CHANNEL));
    }

    public function test_unknown_number_without_a_profile_name_falls_back_to_the_number()
    {
        $resolved = (new CustomerResolver())->resolve('+4915100000005', null);

        $this->assertSame('+4915100000005', $resolved->first_name);
    }

    public function test_resolution_is_idempotent()
    {
        $resolver = new CustomerResolver();

        $first  = $resolver->resolve('+4915100000006', 'Emil Test');
        $second = $resolver->resolve('+4915100000006', 'Emil Test');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerChannel::where('channel', KapsoAccount::CHANNEL)
            ->where('channel_id', '+4915100000006')->count());
    }

    public function test_bsuid_directory_lookup_wins_over_everything()
    {
        $mapped = Customer::createWithoutEmail(['first_name' => 'Greta', 'last_name' => 'Gemappt']);
        \Modules\KapsoWhatsApp\Entities\KapsoContact::create([
            'bsuid' => 'US.ResolverKnown1', 'customer_id' => $mapped->id,
        ]);

        // A different customer owns the phone -- the directory still wins.
        $phoneOwner = Customer::createWithoutEmail(['first_name' => 'Hans', 'last_name' => 'Phone']);
        $phoneOwner->addChannel(KapsoAccount::CHANNEL, '+4915100000020');

        $resolved = (new CustomerResolver())->resolve('+4915100000020', 'Ignored', [
            'bsuid' => 'US.ResolverKnown1', 'parent_bsuid' => null, 'username' => null,
        ]);

        $this->assertSame($mapped->id, $resolved->id);
    }

    public function test_unknown_bsuid_with_known_phone_backfills_the_mapping()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Ines', 'last_name' => 'Bestand']);
        $customer->addChannel(KapsoAccount::CHANNEL, '+4915100000021');

        $resolved = (new CustomerResolver())->resolve('+4915100000021', null, [
            'bsuid' => 'US.Backfill1', 'parent_bsuid' => 'US.ENT.BackfillParent1', 'username' => '@ines',
        ]);

        $this->assertSame($customer->id, $resolved->id);

        $contact = \Modules\KapsoWhatsApp\Entities\KapsoContact::where('bsuid', 'US.Backfill1')->first();
        $this->assertNotNull($contact, 'resolution with a bsuid must record the mapping');
        $this->assertSame($customer->id, (int) $contact->customer_id);
        $this->assertSame('+4915100000021', $contact->phone);
        $this->assertSame('@ines', $contact->username);
        $this->assertSame('US.ENT.BackfillParent1', $contact->parent_bsuid);
    }

    public function test_bsuid_regeneration_same_phone_maps_to_the_same_customer()
    {
        $resolver = new CustomerResolver();

        $first = $resolver->resolve('+4915100000022', 'Jana Alt', [
            'bsuid' => 'US.Regen.Old1', 'parent_bsuid' => null, 'username' => null,
        ]);

        // Meta regenerated the BSUID; the phone is unchanged.
        $second = $resolver->resolve('+4915100000022', 'Jana Alt', [
            'bsuid' => 'US.Regen.New1', 'parent_bsuid' => null, 'username' => null,
        ]);

        $this->assertSame($first->id, $second->id);
        // Both mapping rows exist and point at the same customer; the old
        // one keeps resolving old references.
        $this->assertSame(
            [$first->id, $first->id],
            \Modules\KapsoWhatsApp\Entities\KapsoContact::whereIn('bsuid', ['US.Regen.Old1', 'US.Regen.New1'])
                ->orderBy('bsuid')->pluck('customer_id')->map(function ($id) { return (int) $id; })->all()
        );
    }

    public function test_bsuid_only_customer_is_created_with_username_name_and_bsuid_channel()
    {
        $resolved = (new CustomerResolver())->resolve(null, null, [
            'bsuid' => 'US.PhonelessNew1', 'parent_bsuid' => null, 'username' => '@karla',
        ]);

        $this->assertSame('@karla', $resolved->first_name);
        $this->assertSame([], $resolved->getPhones());
        // With no phone, the bsuid is the channel identity (spec D11).
        $this->assertSame('US.PhonelessNew1', $resolved->getChannelId(KapsoAccount::CHANNEL));
        $this->assertSame(
            $resolved->id,
            (int) \Modules\KapsoWhatsApp\Entities\KapsoContact::where('bsuid', 'US.PhonelessNew1')->value('customer_id')
        );
    }

    public function test_bsuid_only_customer_without_username_is_named_after_the_bsuid()
    {
        $resolved = (new CustomerResolver())->resolve(null, null, [
            'bsuid' => 'US.PhonelessNew2', 'parent_bsuid' => null, 'username' => null,
        ]);

        $this->assertSame('US.PhonelessNew2', $resolved->first_name);
    }

    public function test_learning_a_phone_upgrades_a_bsuid_only_customer()
    {
        $resolver = new CustomerResolver();

        $created = $resolver->resolve(null, null, [
            'bsuid' => 'US.LearnPhone1', 'parent_bsuid' => null, 'username' => '@lena',
        ]);

        // The same contact later arrives with a phone (e.g. inside the
        // 30-day window, or after sharing it).
        $resolved = $resolver->resolve('+4915100000023', null, [
            'bsuid' => 'US.LearnPhone1', 'parent_bsuid' => null, 'username' => '@lena',
        ]);

        $this->assertSame($created->id, $resolved->id);
        $phones = array_column($resolved->fresh()->getPhones(), 'value');
        $this->assertContains('+4915100000023', $phones, 'the learned phone must be appended');
        // addChannel()'s update semantics upgrade the channel row from the
        // bsuid to the phone (spec D11); the directory still owns the bsuid.
        $this->assertSame('+4915100000023', $resolved->getChannelId(KapsoAccount::CHANNEL));
        $this->assertSame(
            '+4915100000023',
            \Modules\KapsoWhatsApp\Entities\KapsoContact::where('bsuid', 'US.LearnPhone1')->value('phone')
        );
    }

    public function test_stale_directory_row_is_dropped_and_rebuilt()
    {
        \Modules\KapsoWhatsApp\Entities\KapsoContact::create([
            'bsuid' => 'US.Stale1', 'customer_id' => 999999999,
        ]);

        $resolved = (new CustomerResolver())->resolve(null, 'Mia Neu', [
            'bsuid' => 'US.Stale1', 'parent_bsuid' => null, 'username' => null,
        ]);

        $this->assertSame('Mia', $resolved->first_name);
        $this->assertSame(
            $resolved->id,
            (int) \Modules\KapsoWhatsApp\Entities\KapsoContact::where('bsuid', 'US.Stale1')->value('customer_id'),
            'the stale row must be replaced by a mapping to the freshly created customer'
        );
        $this->assertSame(1, \Modules\KapsoWhatsApp\Entities\KapsoContact::where('bsuid', 'US.Stale1')->count());
    }
}
