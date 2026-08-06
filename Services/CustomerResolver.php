<?php

namespace Modules\KapsoWhatsApp\Services;

use App\Customer;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Entities\KapsoContact;

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
     * Resolve a WhatsApp contact to a Customer.
     *
     * Order (Stage 5, BSUID-first): directory lookup by bsuid -> existing
     * channel identity by phone -> unique exact phone match -> create.
     * Ambiguous phone matches deliberately create a new customer: linking
     * the wrong one would expose another customer's conversation history,
     * and that is a worse outcome than a duplicate an agent can merge later.
     *
     * $identity is ContactDirectory::extractInbound()'s array (bsuid /
     * parent_bsuid / username). Callers guarantee at least one of $e164 /
     * $identity['bsuid'] is present. Whenever a bsuid is present the
     * mapping is (re)recorded post-resolution -- that single call is the
     * creation path, the backfill path and the regeneration re-map in one.
     */
    public function resolve(?string $e164, ?string $contactName = null, array $identity = []): Customer
    {
        $bsuid = $identity['bsuid'] ?? null;

        $customer = $this->resolveByBsuid($bsuid, $e164);

        if (!$customer && $e164) {
            $customer = Customer::getCustomerByChannel(KapsoAccount::CHANNEL, $e164)
                ?: $this->findUniqueByPhone($e164);
        }

        $created = false;

        if (!$customer) {
            $customer = $this->create($e164, $contactName, $identity);
            $created  = true;
        }

        if ($e164) {
            // No-op when the channel row already holds this phone; upgrades
            // a bsuid-valued row to the phone when one was learned (D11).
            $customer->addChannel(KapsoAccount::CHANNEL, $e164);
        } elseif ($created && $bsuid) {
            // A customer who never had a phone: the bsuid is the only
            // channel identity available (D11). Best-effort only: core's
            // customer_channel.channel_id is string(64), shorter than a
            // bsuid can be (up to 135 chars, see BSUID_PATTERN), and
            // CustomerChannel::create() swallows its own exceptions -- so an
            // over-long bsuid silently creates no channel row here. Harmless
            // because ContactDirectory (kapso_whatsapp_contacts.bsuid,
            // string(191)) is the sole authority for bsuid -> customer
            // resolution; no future code may assume this customer_channel
            // row exists.
            $customer->addChannel(KapsoAccount::CHANNEL, $bsuid);
        }

        if ($bsuid) {
            (new ContactDirectory())->record($bsuid, $customer->id, [
                'phone'        => $e164,
                'username'     => $identity['username'] ?? null,
                'parent_bsuid' => $identity['parent_bsuid'] ?? null,
            ]);
        }

        return $customer;
    }

    /**
     * Directory leg: the bsuid row's customer wins outright when present.
     * A stale row (its customer since deleted, e.g. by an agent merge that
     * removed the duplicate) is dropped so the caller falls through and
     * the post-resolution record() creates a fresh mapping -- this
     * delete-and-recreate is the only way a bsuid ever changes customers.
     */
    protected function resolveByBsuid(?string $bsuid, ?string $e164)
    {
        if (!$bsuid) {
            return null;
        }

        $customerId = (new ContactDirectory())->customerIdFor($bsuid);

        if (!$customerId) {
            return null;
        }

        $customer = Customer::find($customerId);

        if (!$customer) {
            \Log::warning('[KapsoWhatsApp] Contact directory row points at a missing customer, dropping it', [
                'bsuid'       => $bsuid,
                'customer_id' => $customerId,
            ]);
            KapsoContact::where('bsuid', $bsuid)->delete();

            return null;
        }

        if ($e164) {
            $this->learnPhone($customer, $e164);
        }

        return $customer;
    }

    /**
     * A phone newly learned for a directory-resolved customer (typically
     * one created bsuid-only) is appended to their phone list. Exact-E.164
     * comparison against every stored phone, the same normalisation
     * findUniqueByPhone() applies, so a number already present in national
     * format is not duplicated.
     */
    protected function learnPhone(Customer $customer, string $e164)
    {
        foreach ($customer->getPhones() as $phone) {
            if (PhoneNumber::toE164($phone['value'] ?? '', $this->defaultCountryCode) === $e164) {
                return;
            }
        }

        $phones   = $customer->getPhones();
        $phones[] = ['value' => $e164, 'type' => 1];
        $customer->setPhones($phones);
        $customer->save();
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
     *
     * Public since Stage 5: ContactDirectory::captureFromWebhook() reuses it.
     */
    public function findUniqueByPhone($e164)
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
     *
     * Naming precedence: WhatsApp profile name -> username -> phone ->
     * bsuid. The bsuid is an ugly last resort, but an identifiable one --
     * and it is only reachable for a phoneless customer with no profile
     * name and no username.
     */
    protected function create($e164, $contactName, array $identity = [])
    {
        $name = trim((string) $contactName);

        if ($name === '') {
            $name = trim((string) ($identity['username'] ?? ''));
        }

        if ($name === '') {
            $first = (string) ($e164 ?: ($identity['bsuid'] ?? ''));
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

        if ($e164) {
            $customer->setPhones([['value' => $e164, 'type' => 1]]);
            $customer->save();
        }

        return $customer;
    }
}
