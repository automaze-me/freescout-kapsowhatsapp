<?php

namespace Modules\KapsoWhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;
use Modules\KapsoWhatsApp\Exceptions\KapsoApiException;
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
        ]);
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

    public function create()
    {
        $this->authorizeAdmin();

        return view('kapsowhatsapp::account_form', [
            'account'   => new KapsoAccount(),
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $this->validate($request, [
            'name'            => 'required|string|max:191',
            'phone_number_id' => 'required|string|max:64|unique:kapso_whatsapp_accounts,phone_number_id',
            'api_key'         => 'required|string',
            'mailbox_id'      => 'required|integer|exists:mailboxes,id',
        ]);

        $account = new KapsoAccount();
        $this->applyRequest($account, $request);
        $account->save();

        \Session::flash('flash_success_floating', __('Account saved'));

        return redirect()->route('kapsowhatsapp.settings');
    }

    public function edit($id)
    {
        $this->authorizeAdmin();

        return view('kapsowhatsapp::account_form', [
            'account'   => KapsoAccount::findOrFail($id),
            'mailboxes' => Mailbox::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        $this->validate($request, [
            'name'            => 'required|string|max:191',
            'phone_number_id' => 'required|string|max:64|unique:kapso_whatsapp_accounts,phone_number_id,'.$account->id,
            'mailbox_id'      => 'required|integer|exists:mailboxes,id',
        ]);

        $this->applyRequest($account, $request);
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

            return __('Webhook registered with Kapso.');
        });
    }

    public function refreshWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar, KapsoAccount $account) {
            $wasRegistered = $account->isWebhookRegistered();
            $webhook       = $registrar->refresh();

            if ($webhook !== null) {
                return __('Webhook status updated.');
            }

            // refresh() returns null both when nothing was ever registered
            // (nothing to say beyond that) and when it just discovered the
            // webhook is gone from Kapso's side -- in which case it already
            // wrote the specific reason to webhook_error, which is a better
            // answer than a generic "updated".
            return $wasRegistered
                ? $account->webhook_error
                : __('Nothing is registered for this account.');
        });
    }

    public function resumeWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar, KapsoAccount $account) {
            $webhook = $registrar->resume();

            // resume() only returns null after discovering the webhook is
            // gone from Kapso's side (see WebhookRegistrar::markWebhookGone),
            // which already wrote the reason to webhook_error.
            return $webhook !== null
                ? __('Webhook re-enabled.')
                : $account->webhook_error;
        });
    }

    /**
     * One place decides what the admin sees when Kapso says no. Every message
     * names what to change -- an invalid key demands a valid key -- and none
     * of them offers a manual registration path: a documented curl fallback
     * rots the moment Kapso changes its API and invites half-configured
     * installs where nobody knows which system registered what.
     */
    protected function runWebhookAction($id, \Closure $action)
    {
        $this->authorizeAdmin();

        $account = KapsoAccount::findOrFail($id);

        try {
            \Session::flash('flash_success_floating', $action(new WebhookRegistrar($account), $account));
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
     * A blank api_key on edit means "leave unchanged" — the form never renders
     * the existing key back to the browser. The webhook secret is not a form
     * field at all: it is generated by WebhookRegistrar when the webhook is
     * registered, so FreeScout and Kapso can never hold different values.
     */
    protected function applyRequest(KapsoAccount $account, Request $request)
    {
        $account->name                = $request->input('name');
        $account->phone_number_id     = $request->input('phone_number_id');
        $account->business_account_id = $request->input('business_account_id');
        $account->mailbox_id          = (int) $request->input('mailbox_id');
        $account->is_active           = (bool) $request->input('is_active');

        if ($request->filled('api_key')) {
            $account->api_key = $request->input('api_key');
        }
    }
}
