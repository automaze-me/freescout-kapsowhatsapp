@extends('layouts.app')

@section('title', __('WhatsApp Account'))

@section('content')
<div class="section-heading">{{ __('WhatsApp Account') }}</div>

<div class="row-container">
    <form method="POST" action="{{ $account->id ? route('kapsowhatsapp.update', ['id' => $account->id]) : route('kapsowhatsapp.store') }}" class="form-horizontal">
        {{ csrf_field() }}

        <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
            <label class="col-sm-2 control-label">{{ __('Name') }}</label>
            <div class="col-sm-6">
                <input type="text" class="form-control" name="name" value="{{ old('name', $account->name) ?? '' }}" required>
                @include('partials/field_error', ['field' => 'name'])
            </div>
        </div>

        <div class="form-group{{ $errors->has('phone_number_id') ? ' has-error' : '' }}">
            <label class="col-sm-2 control-label">{{ __('Phone Number ID') }}</label>
            <div class="col-sm-6">
                <input type="text" class="form-control" name="phone_number_id" value="{{ old('phone_number_id', $account->phone_number_id) ?? '' }}" required>
                @include('partials/field_error', ['field' => 'phone_number_id'])
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Business Account ID') }}</label>
            <div class="col-sm-6">
                <input type="text" class="form-control" name="business_account_id" value="{{ old('business_account_id', $account->business_account_id) ?? '' }}">
            </div>
        </div>

        <div class="form-group{{ $errors->has('api_key') ? ' has-error' : '' }}">
            <label class="col-sm-2 control-label">{{ __('Kapso API Key') }}</label>
            <div class="col-sm-6">
                <input type="password" class="form-control" name="api_key" autocomplete="new-password" @if (!$account->id) required @endif>
                @include('partials/field_error', ['field' => 'api_key'])
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Webhook Secret') }}</label>
            <div class="col-sm-6">
                <p class="form-control-static text-muted">
                    {{ __('Generated automatically when you register the webhook with Kapso, so FreeScout and Kapso can never hold different secrets.') }}
                </p>
            </div>
        </div>

        <div class="form-group{{ $errors->has('mailbox_id') ? ' has-error' : '' }}">
            <label class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
            <div class="col-sm-6">
                <select name="mailbox_id" class="form-control" required>
                    @foreach ($mailboxes as $mailbox)
                        <option value="{{ $mailbox->id }}" @if ((int) old('mailbox_id', $account->mailbox_id) === $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                    @endforeach
                </select>
                @include('partials/field_error', ['field' => 'mailbox_id'])
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <label><input type="checkbox" name="is_active" value="1" @if (old('is_active', $account->id ? $account->is_active : true)) checked @endif> {{ __('Active') }}</label>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </div>
    </form>
</div>
@endsection
