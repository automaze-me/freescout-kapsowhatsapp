@extends('layouts.app')

@section('title', __('WhatsApp Accounts'))

@section('content')
<div class="section-heading">{{ __('WhatsApp Accounts') }}</div>

<div class="row-container">
    <div class="alert alert-info">
        {{ __('Webhook URL') }}: <code>{{ $webhookUrl }}</code>
    </div>

    @if ($webhookUrlUnreachable)
    <div class="alert alert-warning">
        {{ __('Kapso delivers webhooks from the public internet and cannot reach this address. Registration will succeed, but no WhatsApp message will ever arrive until FreeScout is reachable at a public URL.') }}
    </div>
    @endif

    <p>
        <a href="{{ route('kapsowhatsapp.create') }}" class="btn btn-primary">{{ __('Add Account') }}</a>
    </p>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Phone Number ID') }}</th>
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
            @foreach ($accounts as $account)
            <tr>
                <td>{{ $account->name }}</td>
                <td><code>{{ $account->phone_number_id }}</code></td>
                <td>{{ $account->mailbox ? $account->mailbox->name : '—' }}</td>
                <td>{{ $account->is_active ? __('Yes') : __('No') }}</td>
                <td>
                    @if (!$account->isWebhookRegistered())
                        <span class="text-muted">{{ __('Not registered') }}</span><br>
                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.register', ['id' => $account->id]) }}" style="display:inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-primary btn-xs">{{ __('Register with Kapso') }}</button>
                        </form>
                    @elseif ($account->isWebhookPaused())
                        <span class="text-danger">{{ __('Paused by Kapso') }}</span><br>
                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.resume', ['id' => $account->id]) }}" style="display:inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-default btn-xs">{{ __('Re-enable') }}</button>
                        </form>
                    @else
                        <span class="text-success">{{ __('Active') }}</span><br>
                        <form method="POST" action="{{ route('kapsowhatsapp.webhook.refresh', ['id' => $account->id]) }}" style="display:inline">
                            {{ csrf_field() }}
                            <button type="submit" class="btn btn-default btn-xs">{{ __('Check now') }}</button>
                        </form>
                    @endif

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
                <td>
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
@endsection
