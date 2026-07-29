@extends('layouts.app')

@section('title', __('WhatsApp Account'))

@section('content')
<div class="section-heading">{{ __('WhatsApp Account') }}</div>

@include('partials/flash_messages')

<div class="row-container form-container">
    <div class="row">
        <div class="col-xs-12">
            @if (isset($numbersError) && $numbersError)
                <div class="alert alert-warning margin-top">{{ $numbersError }}</div>
                <p>
                    <a href="{{ route('kapsowhatsapp.settings') }}" class="btn btn-primary">{{ __('Back to WhatsApp Accounts') }}</a>
                </p>
            @else
            <form method="POST" action="{{ $account->id ? route('kapsowhatsapp.update', ['id' => $account->id]) : route('kapsowhatsapp.store') }}" class="form-horizontal margin-top">
                {{ csrf_field() }}

                @if ($account->id)
                <div class="form-group">
                    <label class="col-sm-2 control-label">{{ __('WhatsApp Number') }}</label>
                    <div class="col-sm-6">
                        <p class="form-control-static"><code>{{ $account->phone_number_id }}</code></p>
                        <p class="text-help">{{ __('The number cannot be changed. To use a different number, add it as a new entry.') }}</p>
                    </div>
                </div>
                @else
                <div class="form-group{{ $errors->has('phone_number_id') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('WhatsApp Number') }}</label>
                    <div class="col-sm-6">
                        <select name="phone_number_id" class="form-control input-sized" required>
                            <option value="">{{ __('Select a number') }}</option>
                            @foreach ($numbers as $number)
                                @php
                                    $numberId = (string) ($number['phone_number_id'] ?? '');
                                    $taken    = in_array($numberId, $takenPhoneNumberIds, true);
                                @endphp
                                <option value="{{ $numberId }}"
                                        @if ((string) old('phone_number_id', $account->phone_number_id) === $numberId) selected @endif
                                        @if ($taken) disabled @endif>
                                    {{ \Modules\KapsoWhatsApp\Services\KapsoNumber::label($number) }}@if ($taken) — {{ __('already added') }}@endif
                                </option>
                            @endforeach
                        </select>
                        @include('partials/field_error', ['field' => 'phone_number_id'])
                        <p class="text-help">{{ __('Numbers come from the Kapso project your API key belongs to.') }}</p>
                    </div>
                </div>
                @endif

                <div class="form-group{{ $errors->has('mailbox_id') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Mailbox') }}</label>
                    <div class="col-sm-6">
                        <select name="mailbox_id" class="form-control input-sized" required>
                            @foreach ($mailboxes as $mailbox)
                                <option value="{{ $mailbox->id }}" @if ((int) old('mailbox_id', $account->mailbox_id) === $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                            @endforeach
                        </select>
                        @include('partials/field_error', ['field' => 'mailbox_id'])
                    </div>
                </div>

                <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                    <label class="col-sm-2 control-label">{{ __('Name') }}</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control input-sized" name="name" value="{{ old('name', $account->name) ?? '' }}">
                        @include('partials/field_error', ['field' => 'name'])
                        <p class="text-help">{{ __('Optional. Left blank, the number\'s name in Kapso is used.') }}</p>
                    </div>
                </div>

                <div class="form-group">
                    <div class="col-sm-6 col-sm-offset-2">
                        <label><input type="checkbox" name="is_active" value="1" @if (old('is_active', $account->id ? $account->is_active : true)) checked @endif> {{ __('Active') }}</label>
                    </div>
                </div>

                <div class="form-group margin-top">
                    <div class="col-sm-6 col-sm-offset-2">
                        <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        <a href="{{ route('kapsowhatsapp.settings') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    </div>
                </div>
            </form>
            @endif
        </div>
    </div>
</div>
@endsection
