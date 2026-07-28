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
}
