@extends('secure-access::layouts.guest')

@section('title', __('Create a pending registration'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('activation_url'))
        <div class="sa-mb-4">
            <label class="sa-label" for="activation_url">{{ __('Activation link to share securely') }}</label>
            <input id="activation_url" class="sa-input" type="text" value="{{ session('activation_url') }}" readonly>
        </div>
    @endif
    <form method="POST" action="{{ route('secure-access.invitations.store') }}">
        @csrf
        <div>
            <label class="sa-label" for="first_name">{{ __('First Name') }}</label>
            <input id="first_name" class="sa-input" type="text" name="first_name" value="{{ is_string(old('first_name')) ? old('first_name') : '' }}" maxlength="255" required autocomplete="given-name">
            @include('secure-access::partials.errors', ['messages' => $errors->get('first_name')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="middle_initial">{{ __('Middle Initial (Optional)') }}</label>
            <input id="middle_initial" class="sa-input" type="text" name="middle_initial" value="{{ is_string(old('middle_initial')) ? old('middle_initial') : '' }}" maxlength="1" autocomplete="additional-name">
            @include('secure-access::partials.errors', ['messages' => $errors->get('middle_initial')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="last_name">{{ __('Last Name') }}</label>
            <input id="last_name" class="sa-input" type="text" name="last_name" value="{{ is_string(old('last_name')) ? old('last_name') : '' }}" maxlength="255" required autocomplete="family-name">
            @include('secure-access::partials.errors', ['messages' => $errors->get('last_name')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="date_of_birth">{{ __('Date of Birth') }}</label>
            <input id="date_of_birth" class="sa-input" type="date" name="date_of_birth" value="{{ is_string(old('date_of_birth')) ? old('date_of_birth') : '' }}" min="1900-01-01" max="{{ $latestBirthDate }}" required autocomplete="bday">
            <p class="sa-text">{{ __('You must be at least :age years old to register.', ['age' => $minimumAge]) }}</p>
            @include('secure-access::partials.errors', ['messages' => $errors->get('date_of_birth')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="gender">{{ __('Gender') }}</label>
            <select id="gender" class="sa-input" name="gender" required>
                <option value="" disabled @selected(old('gender') === null)>{{ __('Select…') }}</option>
                @foreach ($genders as $value => $label)
                    <option value="{{ $value }}" @selected(is_scalar(old('gender')) && (string) old('gender') === (string) $value)>{{ __($label) }}</option>
                @endforeach
            </select>
            @include('secure-access::partials.errors', ['messages' => $errors->get('gender')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="email">{{ __('Email Address') }}</label>
            <input id="email" class="sa-input" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
            @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="delivery">{{ __('Activation link delivery') }}</label>
            <select id="delivery" class="sa-input" name="delivery" required>
                <option value="email" @selected(old('delivery', 'email') === 'email')>{{ __('Email the person being invited') }}</option>
                <option value="manual" @selected(old('delivery') === 'manual')>{{ __('I will share the link myself') }}</option>
            </select>
            @include('secure-access::partials.errors', ['messages' => $errors->get('delivery')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="expires_in">{{ __('Registration link expiration') }}</label>
            <select id="expires_in" class="sa-input" name="expires_in" required>
                <option value="5_minutes" @selected(old('expires_in') === '5_minutes')>{{ __('5 minutes') }}</option>
                <option value="15_minutes" @selected(old('expires_in') === '15_minutes')>{{ __('15 minutes') }}</option>
                <option value="1_day" @selected(old('expires_in') === '1_day')>{{ __('1 day') }}</option>
                <option value="2_days" @selected(old('expires_in') === '2_days')>{{ __('2 days') }}</option>
                <option value="2_weeks" @selected(old('expires_in', '2_weeks') === '2_weeks')>{{ __('2 weeks') }}</option>
            </select>
            @include('secure-access::partials.errors', ['messages' => $errors->get('expires_in')])
        </div>
        <fieldset class="sa-mt-4">
            <legend class="sa-label">{{ __('Groups to assign to the new member') }}</legend>
            @foreach ($groups as $group)
                <label class="sa-text">
                    <input type="checkbox" name="groups[]" value="{{ $group->id }}" @checked(in_array($group->id, (array) old('groups', [])))>
                    {{ $group->name }}
                </label>
            @endforeach
        </fieldset>
        <div class="sa-actions">
            <button class="sa-btn" type="submit">{{ __('Create pending registration') }}</button>
        </div>
    </form>
@endsection
