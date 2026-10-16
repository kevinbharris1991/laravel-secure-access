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
            <button class="sa-btn" type="submit">{{ __('Send invitation') }}</button>
        </div>
    </form>
@endsection
