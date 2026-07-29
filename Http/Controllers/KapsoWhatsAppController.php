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
     * registered accounts are checked, and only when the last reading has gone
     * stale, so this is at most one round trip per account per few minutes.
     * A KapsoApiException records the error on the row so it shows up next to
     * the account; the generic catch below is a last-resort guard that only
     * logs, so an unexpected internal error cannot take the settings page down.
     */
    protected function refreshStaleWebhookStatus(KapsoAccount $account)
    {
        if (!$account->isWebhookRegistered()) {
            return;
        }

        if ($account->webhook_checked_at
            && $account->webhook_checked_at->gt(now()->subMinutes(WebhookRegistrar::STALE_AFTER_MINUTES))) {
            return;
        }

        try {
            (new WebhookRegistrar($account))->refresh();
        } catch (KapsoApiException $e) {
            $this->recordWebhookError($account, $e);
        } catch (\Exception $e) {
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
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar) {
            $registrar->refresh();

            return __('Webhook status updated.');
        });
    }

    public function resumeWebhook($id)
    {
        return $this->runWebhookAction($id, function (WebhookRegistrar $registrar) {
            $registrar->resume();

            return __('Webhook re-enabled.');
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
            \Session::flash('flash_success_floating', $action(new WebhookRegistrar($account)));
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
     */
    protected function recordWebhookError(KapsoAccount $account, KapsoApiException $e)
    {
        $account->webhook_error      = $e->getMessage();
        $account->webhook_checked_at = now();
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
