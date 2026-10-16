@extends('secure-access::layouts.guest')

@section('title', __('Activate Account'))

@section('content')
    <h1 class="sa-title">{{ __('Activate your account') }}</h1>
    <p class="sa-text sa-mb-4">{{ __('Welcome, :name! Choose your sign-in details for :email.', ['name' => $registration->name, 'email' => $registration->email]) }}</p>

    @include('secure-access::partials.errors', ['messages' => $errors->get('email')])

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
        @csrf

        <div>
            <label class="sa-label" for="profile_photo">{{ __('Profile Photo') }} <span class="sa-badge">{{ __('Optional') }}</span></label>
            <input id="profile_photo" class="sa-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/gif,image/webp">
            <p class="sa-text">{{ __('JPG, PNG, GIF or WebP, up to :size KB.', ['size' => $maxPhotoSize]) }}</p>
            @include('secure-access::partials.errors', ['messages' => $errors->get('profile_photo')])
        </div>

        @if ($username)
            <div class="sa-mt-4">
                <label class="sa-label" for="username">{{ __('Username') }}</label>
                <input id="username" class="sa-input" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
                @include('secure-access::partials.errors', ['messages' => $errors->get('username')])
            </div>
        @endif

        <div class="sa-mt-4">
            <label class="sa-label" for="password">{{ __('Password') }}</label>
            <input id="password" class="sa-input" type="password" name="password" minlength="12" required autocomplete="new-password">
            <p class="sa-text">{{ __('Use at least 12 characters with uppercase and lowercase letters, numbers and symbols.') }}</p>
            @include('secure-access::partials.errors', ['messages' => $errors->get('password')])
        </div>

        <div class="sa-mt-4">
            <label class="sa-label" for="password_confirmation">{{ __('Confirm Password') }}</label>
            <input id="password_confirmation" class="sa-input" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>

        <div class="sa-actions">
            <button type="submit" class="sa-btn">{{ __('Activate Account') }}</button>
        </div>
    </form>
@endsection
