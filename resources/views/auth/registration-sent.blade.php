@extends('secure-access::layouts.guest')

@section('title', __('Check Your Email'))

@section('content')
    <p>{{ __('We have sent you an activation link. Check your email to choose your username and password, then set up multi-factor authentication.') }}</p>
    <p>{{ __('The link expires in :minutes minutes. If it expires, register again to receive a new link.', ['minutes' => \SecureAccess\Notifications\ActivateAccount::EXPIRES]) }}</p>
    <div class="sa-actions">
        <a class="sa-link" href="{{ route('secure-access.register') }}">{{ __('Back to registration') }}</a>
        <a class="sa-link" href="{{ route('secure-access.login') }}">{{ __('Log in') }}</a>
    </div>
@endsection
