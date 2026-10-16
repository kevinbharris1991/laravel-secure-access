@extends('secure-access::layouts.guest')

@section('title', __('Invite a member'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    <form method="POST" action="{{ route('secure-access.invitations.store') }}">
        @csrf
        <div>
            <label class="sa-label" for="email">{{ __('Email') }}</label>
            <input id="email" class="sa-input" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
            @include('secure-access::partials.errors', ['messages' => $errors->get('email')])
        </div>
        <div class="sa-mt-4">
            <label class="sa-label" for="expires_in">{{ __('Registration link expiration') }}</label>
            <select id="expires_in" class="sa-input" name="expires_in" required>
                <option value="5_minutes" @selected(old('expires_in') === '5_minutes')>{{ __('5 minutes') }}</option>
                <option value="15_minutes" @selected(old('expires_in') === '15_minutes')>{{ __('15 minutes') }}</option>
                <option value="1_day" @selected(old('expires_in') === '1_day')>{{ __('1 day') }}</option>
                <option value="2_weeks" @selected(old('expires_in', '2_weeks') === '2_weeks')>{{ __('2 weeks') }}</option>
            </select>
            @include('secure-access::partials.errors', ['messages' => $errors->get('expires_in')])
        </div>
        <fieldset class="sa-mt-4">
            <legend class="sa-label">{{ __('Groups for the new member') }}</legend>
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
