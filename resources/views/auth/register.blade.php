@extends('secure-access::layouts.guest')

@section('title', __('Register'))

@section('content')
    <form method="POST" action="{{ route('secure-access.register.store') }}">
        @csrf

        <div>
            <label class="sa-label" for="name">{{ __('Name') }}</label>
            <input id="name" class="sa-input" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
            @include('secure-access::partials.errors', ['messages' => $errors->get('name')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="date_of_birth">{{ __('Date of Birth') }}</label>
            <input id="date_of_birth" class="sa-input" type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" required autocomplete="bday" max="{{ now()->subDay()->toDateString() }}">
            @include('secure-access::partials.errors', ['messages' => $errors->get('date_of_birth')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="email">{{ __('Email') }}</label>
            <input id="email" class="sa-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="gender">{{ __('Gender') }}</label>
            <input id="gender" class="sa-input" type="text" name="gender" value="{{ old('gender') }}" required autocomplete="sex" maxlength="255">
            @include('secure-access::partials.errors', ['messages' => $errors->get('gender')])
        </div>

        <div class="sa-mt-4">
            <label for="terms">
                <input id="terms" type="checkbox" name="terms" value="1" required @checked(old('terms'))>
                {{ __('I agree to the') }}
                <a class="sa-link" href="{{ config('secure-access.registration.terms_url', '/terms-of-service') }}" target="_blank" rel="noopener noreferrer">{{ __('Terms of Service') }}</a>.
            </label>
            @include('secure-access::partials.errors', ['messages' => $errors->get('terms')])
        </div>

        <div class="sa-mt-4">
            <label for="privacy_policy">
                <input id="privacy_policy" type="checkbox" name="privacy_policy" value="1" required @checked(old('privacy_policy'))>
                {{ __('I agree to the') }}
                <a class="sa-link" href="{{ config('secure-access.registration.privacy_url', '/privacy-policy') }}" target="_blank" rel="noopener noreferrer">{{ __('Privacy Policy') }}</a>.
            </label>
            @include('secure-access::partials.errors', ['messages' => $errors->get('privacy_policy')])
        </div>

        <div class="sa-actions">
            <a class="sa-link" href="{{ route('secure-access.login') }}">{{ __('Already registered?') }}</a>
            <button type="submit" class="sa-btn">{{ __('Register') }}</button>
        </div>
    </form>
@endsection
