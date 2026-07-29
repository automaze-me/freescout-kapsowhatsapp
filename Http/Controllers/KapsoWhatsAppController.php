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
            $this->refreshStaleWebhookStatus($account);
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

        $this->validate($request, ['api_key' => 'nullable|string|max:512']);

        if (trim((string) $request->input('api_key')) !== '') {
            Settings::setApiKey(trim((string) $request->input('api_key')));
            \Session::flash('flash_success_floating', __('Kapso API key saved.'));
        }

        return redirect()->route('kapsowhatsapp.settings');
    }

    /**
     * Kapso pauses a webhook after a run of failures and never resumes it, so
     * the pause has to become visible without anyone clicking anything. Only
     * registered accounts are checked, and only when the last *attempt* has
     * gone stale, so this is at most one round trip per account per few
     * minutes -- deliberately gated on webhook_check_attempted_at rather than
     * webhook_checked_at: a failing Kapso must still be throttled even though
     * a failed attempt never moves webhook_checked_at (see
     * recordWebhookError()). Gating on the "did we learn anything" timestamp
     * instead would mean a Kapso that is merely slow, not down, gets called
     * again on every single page load, for as long as it stays unwell.
     * A KapsoApiException records the error on the row so it shows up next to
     * the account; the generic catch below is a last-resort guard that only
     * logs, so an unexpected internal error cannot take the settings page
     * down. \Throwable, not \Exception, for the same reason every other
     * must-not-blow-up boundary in this module catches it (KapsoSignature,
     * WebhookController): a PHP \Error here must not 500 the one page an
     * admin uses to fix things.
     */
    protected function refreshStaleWebhookStatus(KapsoAccount $account)
    {
        if (!$account->isWebhookRegistered()) {
            return;
        }

        if ($account->webhook_check_attempted_at
            && $account->webhook_check_attempted_at->gt(now()->subMinutes(WebhookRegistrar::STALE_AFTER_MINUTES))) {
            return;
        }

        try {
            (new WebhookRegistrar($account))->refresh();
        } catch (KapsoApiException $e) {
            $this->recordWebhookError($account, $e);
        } catch (\Throwable $e) {
            \Log::error('[KapsoWhatsApp] Webhook status refresh failed: '.$e->getMessage());
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
     * another account is not a valid choice -- except on that account's own
     * edit form.
     */
    protected function takenPhoneNumberIds($exceptAccountId = null)
    {
        return KapsoAccount::when($exceptAccountId, function ($query) use ($exceptAccountId) {
            return $query->where('id', '<>', $exceptAccountId);
        })->pluck('phone_number_id')->all();
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

        list($applied, $error) = $this->applyRequest($account, $request);

        if (!$applied) {
            return redirect()->back()->withInput()
                ->withErrors(['phone_number_id' => $error]);
        }

        $account->save();

        \Session::flash('flash_success_floating', __('Account saved'));

        return redirect()->route('kapsowhatsapp.settings');
    }

    public function edit($id)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        list($numbers, $numbersError) = $this->availableNumbers();

        return view('kapsowhatsapp::account_form', [
            'account'             => $account,
            'mailboxes'           => Mailbox::orderBy('name')->get(),
            'numbers'             => $numbers,
            'numbersError'        => $numbersError,
            'takenPhoneNumberIds' => $this->takenPhoneNumberIds($account->id),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        $this->validate($request, [
            'name'            => 'nullable|string|max:191',
            'phone_number_id' => 'required|string|max:64|unique:kapso_whatsapp_accounts,phone_number_id,'.$account->id,
            'mailbox_id'      => 'required|integer|exists:mailboxes,id',
        ]);

        list($applied, $error) = $this->applyRequest($account, $request);

        if (!$applied) {
            return redirect()->back()->withInput()
                ->withErrors(['phone_number_id' => $error]);
        }

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
     * The two Meta identifiers are looked up in Kapso's own list rather than
     * read from the request, so a tampered form cannot bind an account to an
     * arbitrary phone number or business account.
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
    protected function applyRequest(KapsoAccount $account, Request $request)
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
        $account->business_account_id = isset($record['business_account_id']) && is_scalar($record['business_account_id'])
            ? (string) $record['business_account_id']
            : null;
        $account->mailbox_id          = (int) $request->input('mailbox_id');
        $account->is_active           = (bool) $request->input('is_active');

        return [true, null];
    }
}
