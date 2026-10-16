@extends('secure-access::layouts.guest')

@section('title', __('Activate Your Account'))

@section('content')
    <p>{{ __('Choose your credentials. Next, you will set up multi-factor authentication before accessing your dashboard.') }}</p>
    @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
    <form method="POST" action="{{ $activationUrl }}">
        @csrf

        @if (isset($fields['username']))
            <div>
                <label class="sa-label" for="username">{{ __('Username') }}</label>
                <input id="username" class="sa-input" type="text" name="username" value="{{ old('username') }}" required autocomplete="username" maxlength="255">
                @include('secure-access::partials.errors', ['messages' => $errors->get('username')])
            </div>
        @endif

        <div class="sa-mt-4">
            <label class="sa-label" for="password">{{ __('Password') }}</label>
            <input id="password" class="sa-input" type="password" name="password" minlength="12" required autocomplete="new-password">
            <p>{{ __('Use at least 12 characters with uppercase and lowercase letters, numbers and symbols.') }}</p>
            @include('secure-access::partials.errors', ['messages' => $errors->get('password')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="password_confirmation">{{ __('Confirm Password') }}</label>
            <input id="password_confirmation" class="sa-input" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>

        <div class="sa-actions">
            <button type="submit" class="sa-btn">{{ __('Continue to MFA Setup') }}</button>
        </div>
    </form>
@endsection
