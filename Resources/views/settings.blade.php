@extends('layouts.app')

@section('title', __('WhatsApp Accounts'))

@section('content')
<div class="section-heading">{{ __('WhatsApp Accounts') }}</div>

@include('partials/flash_messages')

<div class="row-container form-container">
    <div class="row">
        <div class="col-xs-12">

            <form method="POST" action="{{ route('kapsowhatsapp.apikey') }}" class="form-horizontal margin-top">
                {{ csrf_field() }}
                <div class="form-group{{ $errors->has('api_key') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Kapso API Key') }}</label>
                    <div class="col-sm-6">
                        {{-- input-sized-lg keeps the field at core's settings-form width
                             (320px, max-width 100%) instead of spanning the whole column. --}}
                        <div class="input-group input-sized-lg">
                            <input type="password" class="form-control" name="api_key" autocomplete="new-password"
                                   placeholder="{{ $hasApiKey ? __('Saved — enter a new key to replace it') : __('Enter your Kapso API key') }}">
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                            </span>
                        </div>
                        @include('partials/field_error', ['field' => 'api_key'])
                        <p class="text-help">{{ __('One key for this install. Your WhatsApp numbers come from the Kapso project it belongs to.') }}</p>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('kapsowhatsapp.country_code') }}" class="form-horizontal">
                {{ csrf_field() }}
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('Default country code') }}</label>
                    <div class="col-sm-6">
                        <div class="input-group input-sized">
                            <input type="text" class="form-control" name="default_country_code"
                                   value="{{ $defaultCountryCode }}" placeholder="{{ __('e.g. 49') }}">
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                            </span>
                        </div>
                        <p class="text-help">{{ __('Used to complete phone numbers typed in national format (with a leading 0). Numbers WhatsApp delivers are always international already. Leave empty to only accept full international numbers.') }}</p>
                    </div>
                </div>
            </form>

            @if (!$hasApiKey)
                <div class="alert alert-warning">{{ __('Add your Kapso API key above before adding a WhatsApp number.') }}</div>
            @endif

            {{-- The webhook URL itself is deliberately not shown when healthy: since the
                 module registers its own webhook, nothing is ever copied by hand any more.
                 It only appears inside this warning, which has to name the address it is
                 warning about. --}}
            @if ($webhookUrlUnreachable)
            <div class="alert alert-warning">
                {{ __('Kapso delivers webhooks from the public internet and cannot reach this install\'s address (:url). Registration will succeed, but no WhatsApp message will ever arrive until FreeScout is reachable at a public URL.', ['url' => $webhookUrl]) }}
            </div>
            @endif

            <p>
                <a href="{{ route('kapsowhatsapp.create') }}" class="btn btn-primary @if (!$hasApiKey) disabled @endif">{{ __('Add Number') }}</a>
            </p>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Number') }}</th>
                            <th>{{ __('Mailbox') }}</th>
                            <th>{{ __('Active') }}</th>
                            <th>{{ __('Webhook') }}</th>
                            <th>{{ __('Last webhook received') }}</th>
                            <th title="{{ __('Messages that failed to reach the customer in the last 24 hours (e.g. outside the 24h customer service window). This does not reflect Kapso\'s own webhook delivery success rate.') }}">
                                {{ __('Delivery Failures (24h)') }}
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (!count($accounts))
                            <tr>
                                <td colspan="8" class="text-help">{{ __('No WhatsApp numbers added yet.') }}</td>
                            </tr>
                        @endif
                        @foreach ($accounts as $account)
                        <tr>
                            <td>{{ $account->name }}</td>
                            {{-- display_number, not phone_number_id: the id
                                 means nothing to a human (user feedback);
                                 it only appears as the fallback for
                                 accounts with no stored number. --}}
                            <td>{{ $account->display_number }}</td>
                            <td>{{ $account->mailbox ? $account->mailbox->name : '—' }}</td>
                            <td>{{ $account->is_active ? __('Yes') : __('No') }}</td>
                            <td>
                                {{-- Status text and its action button (if any) sit on one line,
                                     vertically centred -- the moved-URL block and the error line
                                     below stay as secondary lines. Registration is automatic now,
                                     so the not-registered branch is text only: there is nothing
                                     left for an admin to click here. --}}
                                <div style="display:flex;align-items:center;gap:6px;">
                                    @if (!$account->isWebhookRegistered())
                                        <span class="text-muted">{{ __('Not registered') }}</span>
                                    @elseif ($account->isWebhookPaused())
                                        <span class="text-danger">{{ __('Paused by Kapso') }}</span>
                                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.resume', ['id' => $account->id]) }}" style="display:inline">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-default btn-xs">{{ __('Re-enable') }}</button>
                                        </form>
                                    @elseif ($account->isWebhookStatusUnknown())
                                        <span class="text-warning">{{ __('Registered, status not confirmed') }}</span>
                                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]) }}" style="display:inline">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-default btn-xs">{{ __('Check now') }}</button>
                                        </form>
                                    @else
                                        <span class="text-success">{{ __('Active') }}</span>
                                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]) }}" style="display:inline">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-default btn-xs">{{ __('Check now') }}</button>
                                        </form>
                                    @endif
                                </div>

                                @if ($account->webhookUrlHasMoved($webhookUrl))
                                    <div class="text-warning small">
                                        {{ __('Registered for a different address:') }} <code>{{ $account->webhook_url }}</code>
                                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.register', ['id' => $account->id]) }}" style="display:inline">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn-link" style="border:none;background:none;padding:0;cursor:pointer;">{{ __('Register again') }}</button>
                                        </form>
                                    </div>
                                @endif

                                @if ($account->webhook_error)
                                    <div class="text-danger small">{{ $account->webhook_error }}</div>
                                @endif
                            </td>
                            <td>{{ $account->last_webhook_at ? $account->last_webhook_at->diffForHumans() : __('Never') }}</td>
                            <td>
                                @if (($failures[$account->id] ?? 0) > 0)
                                    <span class="text-danger">{{ $failures[$account->id] }}</span>
                                @else
                                    0
                                @endif
                            </td>
                            <td class="nowrap">
                                <a href="{{ route('kapsowhatsapp.edit', ['id' => $account->id]) }}">{{ __('Edit') }}</a>
                                &nbsp;|&nbsp;
                                <form method="POST" action="{{ route('kapsowhatsapp.destroy', ['id' => $account->id]) }}"
                                      style="display:inline"
                                      onsubmit="return confirm('{{ __('Are you sure you want to delete this account?') }}');">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn-link text-danger" style="border:none;background:none;padding:0;cursor:pointer;">{{ __('Delete') }}</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
@endsection
