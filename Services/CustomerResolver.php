<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Customer;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

class CustomerResolver
{
    /**
     * Country code used to interpret customer phone numbers stored locally
     * in national format (see findUniqueByPhone()). Defaults to this
     * installation's configured code rather than a hardcoded one — no
     * configuration here is specific to one deployment.
     */
    protected $defaultCountryCode;

    public function __construct(?string $defaultCountryCode = null)
    {
        $this->defaultCountryCode = $defaultCountryCode ?? PhoneNumber::configuredDefaultCountryCode();
    }

    /**
     * Resolve a WhatsApp number to a Customer.
     *
     * Order: existing channel identity -> unique exact phone match -> create.
     * Ambiguous phone matches deliberately create a new customer: linking the
     * wrong one would expose another customer's conversation history, and that
     * is a worse outcome than a duplicate an agent can merge later.
     */
    public function resolve(string $e164, ?string $contactName = null): Customer
    {
        $existing = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, $e164);

        if ($existing) {
            return $existing;
        }

        $customer = $this->findUniqueByPhone($e164) ?: $this->create($e164, $contactName);

        $customer->addChannel(KapsoAccount::CHANNEL, $e164);

        return $customer;
    }

    /**
     * Phones are stored as a JSON list, so matching happens in PHP after a
     * cheap LIKE prefilter rather than in SQL.
     *
     * The prefilter searches on the national significant number (the digits
     * left after stripping the leading "+" and the default country code)
     * rather than the full country-code-prefixed digit string. FreeScout
     * stores each phone both as typed and as `Helper::phoneToNumeric()`
     * output, which preserves leading zeros and never substitutes a country
     * code — so a customer entered the ordinary German way ("0151
     * 12345678") never contains the full "4915112345678" substring, but
     * does contain "15112345678". Over-matching here is harmless: the
     * PHP-side loop below still requires exact PhoneNumber::toE164()
     * equality before anything links, so a broader prefilter only costs a
     * little extra comparison work, never a wrong match.
     */
    protected function findUniqueByPhone($e164)
    {
        $bare = ltrim($e164, '+');

        $defaultCountryCode = $this->defaultCountryCode;

        $national = ($defaultCountryCode !== '' && strpos($bare, $defaultCountryCode) === 0)
            ? substr($bare, strlen($defaultCountryCode))
            : $bare;

        $needle = \Helper::sqlEscapeLike($national);

        $candidates = Customer::where('phones', \Helper::sqlLikeOperator(), '%'.$needle.'%')->get();

        $matches = $candidates->filter(function ($customer) use ($e164) {
            foreach ($customer->getPhones() as $phone) {
                if (PhoneNumber::toE164($phone['value'] ?? '', $this->defaultCountryCode) === $e164) {
                    return true;
                }
            }

            return false;
        });

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->count() > 1) {
            \Log::warning('[KapsoWhatsApp] Ambiguous phone match, creating a new customer', [
                'phone'        => $e164,
                'customer_ids' => $matches->pluck('id')->all(),
            ]);
        }

        return null;
    }

    /**
     * Channel customers legitimately have no email address, so this uses
     * Customer::createWithoutEmail() rather than Customer::create() — the
     * latter has an overridden signature (create($email, $data = [])) meant
     * for the email-first flow and is not a plain Eloquent mass-create.
     */
    protected function create($e164, $contactName)
    {
        $name = trim((string) $contactName);

        if ($name === '') {
            $first = $e164;
            $last  = '';
        } else {
            $parts = preg_split('/\s+/', $name, 2);
            $first = $parts[0];
            $last  = $parts[1] ?? '';
        }

        $customer = Customer::createWithoutEmail([
            'first_name' => $first,
            'last_name'  => $last,
        ]);

        $customer->setPhones([['value' => $e164, 'type' => 1]]);
        $customer->save();

        return $customer;
    }
}
