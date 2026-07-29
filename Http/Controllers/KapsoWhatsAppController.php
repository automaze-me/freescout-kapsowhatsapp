<?php

namespace Modules\KapsoWhatsApp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mailbox;
use Illuminate\Http\Request;
use Modules\KapsoWhatsApp\Entities\KapsoAccount;

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

        // This counts WhatsApp *delivery* failures (KapsoMessage rows with an
        // error, e.g. a message rejected for being outside the 24h customer
        // service window) -- not Kapso's own webhook delivery failure rate,
        // which is a separate, unrelated metric this page has no visibility
        // into. A rising count here means messages are failing to reach
        // customers, not that the webhook is at risk of Kapso's auto-pause.
        $failures = \Modules\KapsoWhatsApp\Entities\KapsoMessage::whereNotNull('error')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('account_id')
            ->selectRaw('account_id, count(*) as total')
            ->pluck('total', 'account_id');

        return view('kapsowhatsapp::settings', [
            'accounts' => KapsoAccount::orderBy('name')->get(),
            'failures' => $failures,
        ]);
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
            'webhook_secret'  => 'required|string',
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

    /**
     * Blank secret fields on edit mean "leave unchanged" — the form never
     * renders existing secrets back to the browser.
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

        if ($request->filled('webhook_secret')) {
            $account->webhook_secret = $request->input('webhook_secret');
        }
    }
}
