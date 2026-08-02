<?php

namespace Modules\KapsoWhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
use Modules\KapsoWhatsApp\Services\KapsoClient;
use Modules\KapsoWhatsApp\Services\KapsoNumber;
use Modules\KapsoWhatsApp\Services\Settings;
use Modules\KapsoWhatsApp\Services\WebhookRegistrar;

class KapsoWhatsAppController extends Controller
{
    protected function authorizeAdmin()
    {
        if (!auth()->user() || !auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    public function settings()
    {
        $this->authorizeAdmin();

        $accounts = KapsoAccount::orderBy('name')->get();

        foreach ($accounts as $account) {
            $this->reconcileWebhook($account);
        }

        // This counts WhatsApp *delivery* failures (KapsoMessage rows with an
        // error, e.g. a message rejected for being outside the 24h customer
        // service window) -- not Kapso's own webhook delivery failure rate,
        // which is what the Webhook column shows.
        $failures = \Modules\KapsoWhatsApp\Entities\KapsoMessage::whereNotNull('error')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('account_id')
            ->selectRaw('account_id, count(*) as total')
            ->pluck('total', 'account_id');

        $webhookUrl = WebhookRegistrar::webhookUrl();

        return view('kapsowhatsapp::settings', [
            'accounts'              => $accounts,
            'failures'              => $failures,
            'webhookUrl'            => $webhookUrl,
            'webhookUrlUnreachable' => WebhookRegistrar::looksUnreachable($webhookUrl),
            'hasApiKey'             => Settings::hasApiKey(),
            'defaultCountryCode'    => \Modules\KapsoWhatsApp\Services\PhoneNumber::configuredDefaultCountryCode(),
        ]);
    }

    /**
     * Blank means "leave unchanged" -- the stored key is never rendered back
     * to the browser, so a blank submit is what an admin who only wanted to
     * look at the page produces.
     */
    public function saveApiKey(Request $request)
    {
        $this->authorizeAdmin();

        $apiKey = trim((string) $request->input('api_key', ''));

        // Deliberately no validate() here: a failed validation flashes the
        // submitted input into the session for old(), and while nothing ever
        // renders api_key back, a secret does not belong in the session store
        // at all. The one rule (a length cap) is enforced by hand instead.
        if (mb_strlen($apiKey) > 512) {
            \Session::flash('flash_error_floating', __('That does not look like a Kapso API key.'));

            return redirect()->route('kapsowhatsapp.settings');
        }

        if ($apiKey !== '') {
            Settings::setApiKey($apiKey);

            // The most common registration failure is a bad/missing key: once
            // the admin has actually saved a new one, every account's
            // throttle is cleared so the very next settings-page load
            // registers/heals them right away instead of waiting out the
            // stale window. A plain query builder update() -- no model
            // events needed, and none of this module's own logic listens
            // for any.
            KapsoAccount::query()->update(['webhook_check_attempted_at' => null]);

            \Session::flash('flash_success_floating', __('Kapso API key saved.'));
        }

        return redirect()->route('kapsowhatsapp.settings');
    }

    /**
     * The default country code used to complete phone numbers agents type
     * in national format (PhoneNumber::configuredDefaultCountryCode()'s
     * backing option) -- a first-class field on the accounts page, not a
     * tinker incantation. Accepts "49", "+49" or "0049" and stores bare
     * digits; a code never starts with 0 after the international prefix is
     * stripped, and ITU codes are at most 4 digits. Blank clears it: the
     * module then declines to guess a country for national-format numbers,
     * the documented default.
     */
    public function saveDefaultCountryCode(Request $request)
    {
        $this->authorizeAdmin();

        $raw = $request->input('default_country_code');

        // A blank submit arrives as NULL, not '' -- core's
        // ConvertEmptyStringsToNull middleware runs on every web request --
        // and both mean "clear" here (this admin-only form always carries
        // the field, so absent-vs-blank needs no distinction).
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            \Option::set('kapsowhatsapp.default_country_code', '');
            \Session::flash('flash_success_floating', __('Default country code cleared — national-format numbers will not be completed.'));

            return redirect()->route('kapsowhatsapp.settings');
        }

        // is_string, never a cast: non-scalar JSON would turn the cast's
        // "Array to string conversion" warning into a thrown ErrorException
        // under this app's error_reporting(-1) bootstrap.
        $normalised = null;

        if (is_string($raw)) {
            $stripped = preg_replace('/^(\+|00)/', '', trim($raw));

            if (preg_match('/^[1-9][0-9]{0,3}$/', (string) $stripped)) {
                $normalised = $stripped;
            }
        }

        if ($normalised === null) {
            \Session::flash('flash_error_floating', __('That does not look like a country code. Enter the bare digits, e.g. 49.'));

            return redirect()->route('kapsowhatsapp.settings');
        }

        \Option::set('kapsowhatsapp.default_country_code', $normalised);
        \Session::flash('flash_success_floating', __('Default country code saved.'));

        return redirect()->route('kapsowhatsapp.settings');
    }

    /**
     * Keeps each account's webhook state true without anyone clicking
     * anything -- it both heals (registers a still-unregistered active
     * account) and refreshes (re-checks one already registered, which is how
     * a Kapso-side pause becomes visible: Kapso pauses a webhook after a run
     * of failures and never resumes it on its own).
     *
     * An unregistered account is only ever registered here when it is
     * active -- an inactive account is never auto-registered by this
     * ambient, page-load-triggered loop, the same rule store() now applies
     * on create (see its is_active gate). An account created inactive gets
     * no webhook and no webhook_check_attempted_at stamp at all, so it
     * arrives here looking exactly like any other stale, unregistered,
     * now-active account the instant it is activated via update() -- this
     * loop is what actually registers it, not update() itself. A registered
     * account is refreshed here regardless of is_active: turning an account
     * off does not touch its webhook in Kapso (see the README), so its
     * status can still change and is still worth showing accurately.
     *
     * Either branch only runs when the last *attempt* has gone stale, so
     * this is at most one round trip per account per few minutes --
     * deliberately gated on webhook_check_attempted_at rather than
     * webhook_checked_at: a failing Kapso must still be throttled even though
     * a failed attempt never moves webhook_checked_at (see
     * recordWebhookError()). Gating on the "did we learn anything" timestamp
     * instead would mean a Kapso that is merely slow, not down, gets called
     * again on every single page load, for as long as it stays unwell.
     * A KapsoApiException records the error on the row so it shows up next to
     * the account; the generic catch below is a last-resort guard that only
     * logs, so an unexpected internal error cannot take the settings page
     * down, or stop the loop from reaching the next account. \Throwable, not
     * \Exception, for the same reason every other must-not-blow-up boundary
     * in this module catches it (KapsoSignature, WebhookController): a PHP
     * \Error here must not 500 the one page an admin uses to fix things.
     */
    protected function reconcileWebhook(KapsoAccount $account)
    {
        $registered = $account->isWebhookRegistered();

        if (!$registered && !$account->is_active) {
            return;
        }

        if ($account->webhook_check_attempted_at
            && $account->webhook_check_attempted_at->gt(now()->subMinutes(WebhookRegistrar::STALE_AFTER_MINUTES))) {
            return;
        }

        try {
            $registrar = new WebhookRegistrar($account);
            $registered ? $registrar->refresh() : $registrar->register();
        } catch (KapsoApiException $e) {
            $this->recordWebhookError($account, $e);
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] Webhook reconciliation failed: '.$e->getMessage());
        }
    }

    /**
     * The numbers the admin may choose from, plus whatever went wrong instead.
     *
     * Returns [records, error]: exactly one of them is meaningful. Rendering
     * this on a GET means a Kapso outage must degrade to a message on the
     * form, never to a 500 on the page an admin opens to fix things.
     */
    protected function availableNumbers()
    {
        if (!Settings::hasApiKey()) {
            return [[], __('No Kapso API key is configured yet. Add one on the WhatsApp Accounts page, then come back.')];
        }

        try {
            $numbers = (new KapsoClient(new KapsoAccount()))->listPhoneNumbers();
        } catch (KapsoApiException $e) {
            return [[], $e->getMessage()];
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] Could not list phone numbers: '.$e->getMessage());

            return [[], __('Could not load the WhatsApp numbers from Kapso.')];
        }

        if (!$numbers) {
            return [[], __('This Kapso project has no WhatsApp numbers yet. Add one in Kapso, then come back.')];
        }

        return [$numbers, null];
    }

    /**
     * phone_number_id is unique per account, so a number already bound to
     * another account is not a valid choice on the create form. (The edit
     * form has no such choice to make at all -- the number is immutable
     * after creation.)
     */
    protected function takenPhoneNumberIds()
    {
        return KapsoAccount::pluck('phone_number_id')->all();
    }

    public function create()
    {
        $this->authorizeAdmin();

        list($numbers, $numbersError) = $this->availableNumbers();

        return view('kapsowhatsapp::account_form', [
            'account'             => new KapsoAccount(),
            'mailboxes'           => Mailbox::orderBy('name')->get(),
            'numbers'             => $numbers,
            'numbersError'        => $numbersError,
            'takenPhoneNumberIds' => $this->takenPhoneNumberIds(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $this->validate($request, [
            'name'            => 'nullable|string|max:191',
            'phone_number_id' => 'required|string|max:64|unique:kapso_whatsapp_accounts,phone_number_id',
            'mailbox_id'      => 'required|integer|exists:mailboxes,id',
        ]);

        $account = new KapsoAccount();

        list($applied, $error) = $this->applyCreateRequest($account, $request);

        if (!$applied) {
            return redirect()->back()->withInput()
                ->withErrors(['phone_number_id' => $error]);
        }

        $account->save();

        // Registering is gated on is_active, not unconditional: Kapso will
        // happily create a webhook for a number attached to an inactive
        // account, but KapsoAccount::findByPhoneNumberId() only matches
        // active accounts, so KapsoSignature would then 403 every single
        // delivery to it -- and ~15 minutes of 403s is exactly what makes
        // Kapso auto-pause a webhook on its own. Worse, that does not
        // self-heal when the admin later flips the account active:
        // isWebhookRegistered() would already be true, so reconcileWebhook()
        // would take the refresh() branch and just keep reporting "Paused by
        // Kapso" -- the manual Re-enable click this whole feature exists to
        // remove. Leaving webhook_check_attempted_at null on the inactive
        // branch (it already is, on a freshly created account) is what makes
        // this self-heal instead: the moment the account is activated via
        // update(), the very next settings-page load finds it unregistered
        // and stale and registers it for real. Do not "simplify" this gate
        // away -- see WebhookAdminActionsTest/AdminAccountsTest for the
        // regression it protects against.
        if ($account->is_active) {
            $this->registerNewAccountWebhook($account);
        } else {
            \Session::flash('flash_success_floating', __('Account saved'));
        }

        return redirect()->route('kapsowhatsapp.settings');
    }

    /**
     * The manual "Register with Kapso" step is gone: creation registers the
     * webhook itself, right after the account row is saved, when the account
     * was created active (see store()'s is_active gate above -- this is
     * never called for an inactive create). The account stays saved either
     * way -- a Kapso outage at this exact moment must not roll back or lose
     * data an admin already successfully entered, and the settings-page loop
     * (reconcileWebhook()) will keep retrying on its own. Same try/catch
     * discipline as reconcileWebhook(): a KapsoApiException records the
     * reason on the row via recordWebhookError() (which also stamps
     * webhook_check_attempted_at, so this attempt counts against the
     * throttle immediately -- no double call from the settings page a moment
     * later); any other \Throwable only logs, so a PHP \Error here cannot
     * 500 the response to a create that otherwise succeeded.
     */
    protected function registerNewAccountWebhook(KapsoAccount $account)
    {
        try {
            (new WebhookRegistrar($account))->register();

            \Session::flash('flash_success_floating', __('Account saved. Webhook registered with Kapso.'));
        } catch (KapsoApiException $e) {
            $this->recordWebhookError($account, $e);

            \Session::flash('flash_error_floating', __('Account saved, but the webhook could not be registered: :error It will be retried automatically.', ['error' => $e->getMessage()]));
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] Webhook registration on create failed: '.$e->getMessage());

            \Session::flash('flash_error_floating', __('Account saved, but the webhook could not be registered. It will be retried automatically.'));
        }
    }

    /**
     * No availableNumbers() call: the number is immutable after creation, so
     * the edit page has nothing to ask Kapso for. That makes it render with
     * zero outbound HTTP -- identically whether Kapso is up, down or slow --
     * which is the whole point: an admin who needs to rename, re-mailbox or
     * deactivate an account during a Kapso outage must still get a form.
     */
    public function edit($id)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        return view('kapsowhatsapp::account_form', [
            'account'   => $account,
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    /**
     * phone_number_id and business_account_id are not read from the request
     * at all on this path -- not looked up, not validated, just never
     * touched. The number is the account's identity once created; an admin
     * who wants a different number adds a new account row instead. That
     * also means there is no Kapso call here and so nothing that can fail:
     * this always either validates and saves, or fails validation.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        $this->validate($request, [
            'name'       => 'nullable|string|max:191',
            'mailbox_id' => 'required|integer|exists:mailboxes,id',
        ]);

        $name = trim((string) $request->input('name'));

        // Blank means "leave the existing name alone" -- unlike create,
        // there is no Kapso record left to re-derive a name from here.
        if ($name !== '') {
            $account->name = $name;
        }

        $account->mailbox_id = (int) $request->input('mailbox_id');
        $account->is_active  = (bool) $request->input('is_active');

        $account->save();

        \Session::flash('flash_success_floating', __('Account saved'));

        return redirect()->route('kapsowhatsapp.settings');
    }

    public function destroy($id)
    {
        $this->authorizeAdmin();

        KapsoAccount::findOrFail($id)->delete();

        \Session::flash('flash_success_floating', __('Account deleted'));

        return redirect()->route('kapsowhatsapp.settings');
    }

    public function registerWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar) {
            $registrar->register();

            return [true, __('Webhook registered with Kapso.')];
        });
    }

    public function refreshWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar, KapsoAccount $account) {
            $wasRegistered = $account->isWebhookRegistered();
            $webhook       = $registrar->refresh();

            if ($webhook !== null) {
                return [true, __('Webhook status updated.')];
            }

            // refresh() returns null both when nothing was ever registered
            // (nothing to say beyond that -- not bad news, just a no-op) and
            // when it just discovered the webhook is gone from Kapso's side,
            // which already wrote the specific reason to webhook_error and
            // is genuinely bad news: WhatsApp messages have stopped arriving.
            return $wasRegistered
                ? [false, $account->webhook_error]
                : [true, __('Nothing is registered for this account.')];
        });
    }

    public function resumeWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar, KapsoAccount $account) {
            $webhook = $registrar->resume();

            // resume() only returns null after discovering the webhook is
            // gone from Kapso's side (see WebhookRegistrar::markWebhookGone),
            // which already wrote the reason to webhook_error. That is the
            // one button admins press to recover a paused webhook, so
            // "actually there's nothing left to resume" must read as bad
            // news, not a green checkmark.
            return $webhook !== null
                ? [true, __('Webhook re-enabled.')]
                : [false, $account->webhook_error];
        });
    }

    /**
     * One place decides what the admin sees when Kapso says no. Every message
     * names what to change -- an invalid key demands a valid key -- and none
     * of them offers a manual registration path: a documented curl fallback
     * rots the moment Kapso changes its API and invites half-configured
     * installs where nobody knows which system registered what.
     *
     * $action returns [bool $success, string $message]: reaching Kapso
     * without an exception is not the same as good news for the admin (e.g.
     * discovering the webhook it held is gone), so success/failure is decided
     * by the closure explicitly rather than inferred from "did it throw".
     * A thrown KapsoApiException -- the request itself failing -- is always
     * an error flash, handled the same way regardless of which action ran.
     */
    protected function runWebhookAction($id, \Closure $action)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        try {
            [$success, $message] = $action(new WebhookRegistrar($account), $account);

            \Session::flash($success ? 'flash_success_floating' : 'flash_error_floating', $message);
        } catch (KapsoApiException $e) {
            $this->recordWebhookError($account, $e);

            \Session::flash('flash_error_floating', $e->getMessage());
        }

        return redirect()->route('kapsowhatsapp.settings');
    }

    /**
     * Persists a Kapso API failure on the account row so it is visible next
     * to the account on the settings page, without needing anyone to click
     * anything.
     *
     * Deliberately does NOT stamp webhook_checked_at: a failed check is not
     * a check. Stamping it here previously made a transient failure stick --
     * refreshStaleWebhookStatus() saw a fresh timestamp and skipped the very
     * recheck that could have corrected a wrong diagnosis, so an admin
     * hitting a spurious error could loop on it forever. Leaving the old
     * timestamp alone means the next settings-page load tries again for
     * real.
     *
     * It DOES stamp webhook_check_attempted_at, which is the separate job
     * webhook_checked_at used to (wrongly) do double duty for: without it, a
     * Kapso that keeps failing -- degraded rather than fully down -- would be
     * re-called on every single settings-page load with no backoff at all.
     */
    protected function recordWebhookError(KapsoAccount $account, KapsoApiException $e)
    {
        $account->webhook_error              = $e->getMessage();
        $account->webhook_check_attempted_at = now();
        $account->save();
    }

    /**
     * Create-only: the two Meta identifiers are resolved from Kapso's own
     * list rather than read from the request, so a tampered form cannot bind
     * an account to an arbitrary phone number or business account. On update
     * they are immutable instead -- see update(), which never calls this.
     *
     * Returns [success, error]. Kapso being unreachable and the submitted
     * number simply not being in the project are different failures and must
     * not be reported the same way: a Kapso outage is not evidence of bad
     * data, and telling an admin "that number is not in your project" during
     * an outage reads as data corruption, not as "try again in a minute".
     * Either way nothing is written -- this stays fail-closed.
     *
     * The webhook secret is not a form field at all: WebhookRegistrar mints it
     * at registration time so FreeScout and Kapso cannot hold different values.
     */
    protected function applyCreateRequest(KapsoAccount $account, Request $request)
    {
        list($numbers, $numbersError) = $this->availableNumbers();

        if ($numbersError) {
            return [false, $numbersError];
        }

        $record = KapsoNumber::find($numbers, $request->input('phone_number_id'));

        if (!$record) {
            return [false, __('That number is not one of the WhatsApp numbers in your Kapso project. Reload the page and pick again.')];
        }

        $name = trim((string) $request->input('name'));

        if ($name === '') {
            // The number's own human name (e.g. "Acme GmbH"), not the fuller
            // "+49 151 1 — Acme GmbH" label() builds for the dropdown: this
            // is naming one already-chosen account, not disambiguating it
            // from a list. Only falls back to displayNumber() when Kapso has
            // given neither verified_name nor name, so the account can never
            // end up with a blank one -- and never to label(), which can
            // append a quality rating: that is a moment-in-time signal, and
            // baking it into a stored name would leave the account
            // permanently misnamed once the rating recovers.
            $name = KapsoNumber::humanName($record);

            if ($name === '') {
                $name = KapsoNumber::displayNumber($record);
            }
        }

        $account->name                = $name;
        $account->phone_number_id     = (string) $record['phone_number_id'];
        // The human-readable number for the admin UI (null, not '', when
        // Meta hasn't confirmed one -- display_number then falls back to
        // the id).
        $account->phone_number        = KapsoNumber::phoneNumber($record) ?: null;
        $account->business_account_id = isset($record['business_account_id']) && is_scalar($record['business_account_id'])
            ? (string) $record['business_account_id']
            : null;
        $account->mailbox_id          = (int) $request->input('mailbox_id');
        $account->is_active           = (bool) $request->input('is_active');

        return [true, null];
    }
}
