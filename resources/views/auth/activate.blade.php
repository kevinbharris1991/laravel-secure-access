@extends('secure-access::layouts.guest')

@section('title', __('Activate Account'))

@section('content')
    <h1 class="sa-title">{{ __('Activate your account') }}</h1>
    <p class="sa-text sa-mb-4">{{ __('Welcome, :name! Choose your sign-in details for :email.', ['name' => $registration->name ?: $registration->email, 'email' => $registration->email]) }}</p>

    @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
    @include('secure-access::partials.errors', ['messages' => $errors->get('activation')])

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data">
        @csrf

        @if ($completeProfile)
            <div>
                <label class="sa-label" for="first_name">{{ __('First Name') }}</label>
                <input id="first_name" class="sa-input" type="text" name="first_name" value="{{ old('first_name') }}" required autocomplete="given-name">
                @include('secure-access::partials.errors', ['messages' => $errors->get('first_name')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-label" for="middle_initial">{{ __('Middle Initial (Optional)') }}</label>
                <input id="middle_initial" class="sa-input" type="text" name="middle_initial" value="{{ old('middle_initial') }}" maxlength="1" autocomplete="additional-name">
                @include('secure-access::partials.errors', ['messages' => $errors->get('middle_initial')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-label" for="last_name">{{ __('Last Name') }}</label>
                <input id="last_name" class="sa-input" type="text" name="last_name" value="{{ old('last_name') }}" required autocomplete="family-name">
                @include('secure-access::partials.errors', ['messages' => $errors->get('last_name')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-label" for="date_of_birth">{{ __('Date of Birth') }}</label>
                <input id="date_of_birth" class="sa-input" type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" min="1900-01-01" max="{{ $latestBirthDate }}" required autocomplete="bday">
                <p class="sa-text">{{ __('You must be at least :age years old to register.', ['age' => $minimumAge]) }}</p>
                @include('secure-access::partials.errors', ['messages' => $errors->get('date_of_birth')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-label" for="gender">{{ __('Gender') }}</label>
                <select id="gender" class="sa-input" name="gender" required>
                    <option value="" disabled @selected(old('gender') === null)>{{ __('Select…') }}</option>
                    @foreach ($genders as $value => $label)
                        <option value="{{ $value }}" @selected((string) old('gender') === (string) $value)>{{ __($label) }}</option>
                    @endforeach
                </select>
                @include('secure-access::partials.errors', ['messages' => $errors->get('gender')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-text" for="terms">
                    <input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms')) required>
                    {!! __('I agree to the :terms.', ['terms' => '<a class="sa-link" href="'.e($termsUrl).'" target="_blank" rel="noopener">'.e(__('Terms of Service')).'</a>']) !!}
                </label>
                @include('secure-access::partials.errors', ['messages' => $errors->get('terms')])
            </div>
            <div class="sa-mt-4">
                <label class="sa-text" for="privacy">
                    <input id="privacy" type="checkbox" name="privacy" value="1" @checked(old('privacy')) required>
                    {!! __('I have read and understand the :policy.', ['policy' => '<a class="sa-link" href="'.e($privacyUrl).'" target="_blank" rel="noopener">'.e(__('Privacy Policy')).'</a>']) !!}
                </label>
                @include('secure-access::partials.errors', ['messages' => $errors->get('privacy')])
            </div>
        @endif

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
