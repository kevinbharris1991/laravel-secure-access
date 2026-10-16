@extends('secure-access::layouts.guest')

@section('title', __('Log in'))

@section('content')
    <form method="POST" action="{{ route('secure-access.login.store') }}">
        @csrf

        <div>
            <label class="sa-label" for="identifier">{{ __('Email or Username') }}</label>
            <input id="identifier" class="sa-input" type="text" name="identifier" value="{{ old('identifier') }}" required autofocus autocomplete="username">
            @include('secure-access::partials.errors', ['messages' => $errors->get('identifier')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="password">{{ __('Password') }}</label>
            <input id="password" class="sa-input" type="password" name="password" required autocomplete="current-password">
            @include('secure-access::partials.errors', ['messages' => $errors->get('password')])
        </div>

        <div class="sa-actions">
            @if (Route::has('password.request'))
                <a class="sa-link" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
            @endif

            <button type="submit" class="sa-btn">{{ __('Log in') }}</button>
        </div>
    </form>
@endsection
