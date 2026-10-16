@extends('secure-access::layouts.guest')

@section('title', __('Register'))

@section('content')
    <form method="POST" action="{{ route('secure-access.register.store') }}">
        @csrf

        @if (isset($fields['name']))
            <div>
                <label class="sa-label" for="name">{{ __('Name') }}</label>
                <input id="name" class="sa-input" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                @include('secure-access::partials.errors', ['messages' => $errors->get('name')])
            </div>
        @endif

        <div class="sa-mt-4">
            <label class="sa-label" for="email">{{ __('Email') }}</label>
            <input id="email" class="sa-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
        </div>

        @if (isset($fields['username']))
            <div class="sa-mt-4">
                <label class="sa-label" for="username">{{ __('Username') }}</label>
                <input id="username" class="sa-input" type="text" name="username" value="{{ old('username') }}" required autocomplete="username">
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
            <a class="sa-link" href="{{ route('secure-access.login') }}">{{ __('Already registered?') }}</a>
            <button type="submit" class="sa-btn">{{ __('Register') }}</button>
        </div>
    </form>
@endsection
