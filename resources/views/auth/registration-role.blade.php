@extends('secure-access::layouts.guest')

@section('title', __('About you'))

@section('content')
    <form method="POST" action="{{ route('secure-access.register.role.store') }}">
        @csrf

        <fieldset>
            <legend class="sa-label">{{ __('Which best describes you?') }}</legend>
            @foreach ($registrationRoles as $value => $label)
                <label class="sa-text">
                    <input type="radio" name="registration_role" value="{{ $value }}" @checked(old('registration_role') === $value) required>
                    {{ __($label) }}
                </label>
            @endforeach
            @include('secure-access::partials.errors', ['messages' => $errors->get('registration_role')])
        </fieldset>

        <div class="sa-actions">
            <button type="submit" class="sa-btn">{{ __('Continue to multi-factor authentication') }}</button>
        </div>
    </form>
@endsection
