@php($label = \SecureAccess\Models\MfaMethod::typeLabel($type))

@extends($enrolling ? 'secure-access::layouts.guest' : 'secure-access::layouts.app')

@section('title', $label)
@section('header', __('Add :method', ['method' => $label]))

@section('content')
    <div @class(['sa-section' => ! $enrolling])>
        <div>
            <h1 class="sa-title">{{ $label }}</h1>
            <p class="sa-text">{{ \SecureAccess\Models\MfaMethod::typeDescription($type) }}</p>

            @if ($type === \SecureAccess\Models\MfaMethod::TYPE_TOTP)
                <p class="sa-text sa-mt-4">
                    {{ __('Scan the QR code with your authenticator app, or enter the setup key manually, then enter the 6-digit code it shows.') }}
                </p>

                <div class="sa-qr">{!! $qrCode !!}</div>

                <p class="sa-text">{{ __('Setup key') }}: <span class="sa-code">{{ $secret }}</span></p>

                <form method="POST" action="{{ route('secure-access.setup.store', $type) }}" class="sa-mt-4">
                    @csrf
                    @include('secure-access::mfa.setup.name-field')

                    <div class="sa-mt-4">
                        <label class="sa-label" for="code">{{ __('Code') }}</label>
                        <input id="code" class="sa-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                        @include('secure-access::partials.errors', ['messages' => $errors->get('code')])
                    </div>

                    @include('secure-access::mfa.setup.actions', ['submit' => __('Confirm')])
                </form>
            @elseif ($type === \SecureAccess\Models\MfaMethod::TYPE_SMS)
                <form method="POST" action="{{ route('secure-access.setup.sms.send') }}" class="sa-mt-4">
                    @csrf
                    <label class="sa-label" for="phone">{{ __('Mobile phone number') }}</label>
                    <input id="phone" class="sa-input" type="tel" name="phone" value="{{ old('phone', $phone) }}" placeholder="+15555550123" autocomplete="tel" required @if (! $phone) autofocus @endif>
                    @include('secure-access::partials.errors', ['messages' => $errors->get('phone')])

                    <div class="sa-actions">
                        <button type="submit" class="{{ $phone ? 'sa-btn sa-btn-secondary' : 'sa-btn' }}">
                            {{ $phone ? __('Resend code') : __('Send code') }}
                        </button>
                    </div>
                </form>

                @if ($phone)
                    <form method="POST" action="{{ route('secure-access.setup.store', $type) }}" class="sa-mt-4">
                        @csrf
                        @include('secure-access::mfa.setup.name-field')

                        <div class="sa-mt-4">
                            <label class="sa-label" for="code">{{ __('Code') }}</label>
                            <input id="code" class="sa-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                            @include('secure-access::partials.errors', ['messages' => $errors->get('code')])
                        </div>

                        @include('secure-access::mfa.setup.actions', ['submit' => __('Confirm')])
                    </form>
                @else
                    @include('secure-access::mfa.setup.actions', ['submit' => null])
                @endif
            @else
                <form method="POST" action="{{ route('secure-access.setup.store', $type) }}" data-webauthn="create" class="sa-mt-4">
                    @csrf
                    <input type="hidden" name="credential">
                    <script type="application/json" data-webauthn-options>@json($options)</script>

                    @include('secure-access::mfa.setup.name-field')
                    @include('secure-access::partials.errors', ['messages' => $errors->get('credential')])
                    <ul class="sa-error" data-webauthn-error hidden><li></li></ul>

                    @include('secure-access::mfa.setup.actions', [
                        'submit' => $type === \SecureAccess\Models\MfaMethod::TYPE_BIOMETRIC ? __('Register biometrics') : __('Register security key'),
                    ])
                </form>

                @include('secure-access::partials.webauthn-script')
            @endif
        </div>
    </div>
@endsection
