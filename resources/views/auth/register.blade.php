@extends('secure-access::layouts.guest')

@section('title', __('Register'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->get('activation')])

    <form method="POST" action="{{ route('secure-access.register.store') }}">
        @csrf

        <div>
            <label class="sa-label" for="first_name">{{ __('First Name') }}</label>
            <input id="first_name" class="sa-input" type="text" name="first_name" value="{{ old('first_name') }}" required autofocus autocomplete="given-name">
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
            <label class="sa-label" for="email">{{ __('Email') }}</label>
            <input id="email" class="sa-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
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

        <fieldset class="sa-mt-4">
            <legend class="sa-label">{{ __('Which best describes you?') }}</legend>
            @foreach ($registrationRoles as $value => $label)
                <label class="sa-text">
                    <input type="radio" name="registration_role" value="{{ $value }}" @checked(old('registration_role') === $value) required>
                    {{ __($label) }}
                </label>
            @endforeach
            @include('secure-access::partials.errors', ['messages' => $errors->get('registration_role')])
        </fieldset>

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

        <div class="sa-actions">
            <a class="sa-link" href="{{ route('secure-access.login') }}">{{ __('Already registered?') }}</a>
            <button type="submit" class="sa-btn">{{ __('Register') }}</button>
        </div>
    </form>
@endsection
