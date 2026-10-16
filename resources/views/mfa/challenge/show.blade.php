@extends('secure-access::layouts.guest')

@section('title', __('Verify your identity'))

@section('content')
    <h1 class="sa-title">{{ $method->name }}</h1>

    @if ($method->type === \SecureAccess\Models\MfaMethod::TYPE_TOTP)
        <p class="sa-text">{{ __('Enter the code shown in your authenticator app.') }}</p>

        <form method="POST" action="{{ route('secure-access.challenge.verify', $method) }}" class="sa-mt-4">
            @csrf
            <label class="sa-label" for="code">{{ __('Code') }}</label>
            <input id="code" class="sa-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            @include('secure-access::partials.errors', ['messages' => $errors->get('code')])

            <div class="sa-actions">
                <button type="submit" class="sa-btn">{{ __('Verify') }}</button>
            </div>
        </form>
    @elseif ($method->type === \SecureAccess\Models\MfaMethod::TYPE_SMS)
        <p class="sa-text">
            {{ $codeSent
                ? __('Enter the code we sent to :phone.', ['phone' => $method->maskedPhone()])
                : __('We will send a verification code to :phone.', ['phone' => $method->maskedPhone()]) }}
        </p>

        @if ($codeSent)
            <form method="POST" action="{{ route('secure-access.challenge.verify', $method) }}" class="sa-mt-4">
                @csrf
                <label class="sa-label" for="code">{{ __('Code') }}</label>
                <input id="code" class="sa-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                @include('secure-access::partials.errors', ['messages' => $errors->get('code')])

                <div class="sa-actions">
                    <button type="submit" class="sa-btn">{{ __('Verify') }}</button>
                </div>
            </form>
        @else
            @include('secure-access::partials.errors', ['messages' => $errors->get('code')])
        @endif

        <form method="POST" action="{{ route('secure-access.challenge.send', $method) }}" class="sa-actions">
            @csrf
            <button type="submit" class="{{ $codeSent ? 'sa-link' : 'sa-btn' }}">
                {{ $codeSent ? __('Resend code') : __('Send code') }}
            </button>
        </form>
    @else
        <p class="sa-text">
            {{ $method->type === \SecureAccess\Models\MfaMethod::TYPE_BIOMETRIC
                ? __('Use your fingerprint, face or device screen lock to continue.')
                : __('Insert or tap your security key to continue.') }}
        </p>

        <form method="POST" action="{{ route('secure-access.challenge.verify', $method) }}" data-webauthn="get" class="sa-mt-4">
            @csrf
            <input type="hidden" name="credential">
            <script type="application/json" data-webauthn-options>@json($options)</script>
            @include('secure-access::partials.errors', ['messages' => array_merge($errors->get('code'), $errors->get('credential'))])
            <ul class="sa-error" data-webauthn-error hidden><li></li></ul>

            <div class="sa-actions">
                <button type="submit" class="sa-btn">
                    {{ $method->type === \SecureAccess\Models\MfaMethod::TYPE_BIOMETRIC ? __('Use biometrics') : __('Use security key') }}
                </button>
            </div>
        </form>

        @include('secure-access::partials.webauthn-script')
    @endif

    <div class="sa-actions sa-between">
        @if ($hasOtherMethods)
            <a class="sa-link" href="{{ route('secure-access.challenge') }}">{{ __('Use another method') }}</a>
        @else
            <span></span>
        @endif

        @include('secure-access::partials.cancel')
    </div>
@endsection
