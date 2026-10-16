@extends('secure-access::layouts.guest')

@section('title', __('Check Your Email'))

@section('content')
    <h1 class="sa-title">{{ __('Check your email') }}</h1>

    <p class="sa-text sa-mt-4">
        @if ($email)
            {{ __('We sent an activation link to :email.', ['email' => $email]) }}
        @else
            {{ __('We sent you an activation link.') }}
        @endif
        {{ __('Click the link to choose your username and password and activate your account. The link expires in five minutes.') }}
    </p>

    <div class="sa-actions sa-between">
        <a class="sa-link" href="{{ route('secure-access.register') }}">{{ __('Didn\'t get the email? Register again') }}</a>
        <a class="sa-link" href="{{ route('secure-access.login') }}">{{ __('Log in') }}</a>
    </div>
@endsection
