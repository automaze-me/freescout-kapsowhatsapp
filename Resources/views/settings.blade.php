@extends('layouts.app')

@section('title', __('WhatsApp Accounts'))

@section('content')
<div class="section-heading">{{ __('WhatsApp Accounts') }}</div>

<div class="row-container">
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
                <th>{{ __('Last webhook received') }}</th>
                <th>{{ __('Failures (24h)') }}</th>
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
                <td>{{ $account->last_webhook_at ? $account->last_webhook_at->diffForHumans() : __('Never') }}</td>
                <td>
                    @if (($failures[$account->id] ?? 0) > 0)
                        <span class="text-danger">{{ $failures[$account->id] }}</span>
                    @else
                        0
                    @endif
                </td>
                <td><a href="{{ route('kapsowhatsapp.edit', ['id' => $account->id]) }}">{{ __('Edit') }}</a></td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
