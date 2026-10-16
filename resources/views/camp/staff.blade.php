@extends('secure-access::layouts.app')

@section('title', __('Staff accounts'))
@section('header', __('Staff accounts'))

@section('content')
    @if ($errors->any())
        <div role="alert" class="sa-section">
            @include('secure-access::partials.errors', ['messages' => $errors->all()])
        </div>
    @endif

    <p>{{ __('Manage account details and group assignments. Password resets do not remove multi-factor authentication.') }}</p>

    @can('secure-access', [$createPermission])
        @if ($canCreate)
            <section class="sa-section" aria-labelledby="create-staff-heading">
                <h2 id="create-staff-heading">{{ __('Create staff account') }}</h2>
                <form method="POST" action="{{ route('secure-access.staff.store') }}">
                    @csrf
                    <label class="sa-label" for="new-name">{{ __('Name') }}</label>
                    <input class="sa-input" id="new-name" name="name" required maxlength="255" autocomplete="name" value="{{ old('name') }}">
                    <label class="sa-label" for="new-username">{{ __('Username') }}</label>
                    <input class="sa-input" id="new-username" name="username" required maxlength="255" autocomplete="off" value="{{ old('username') }}">
                    <label class="sa-label" for="new-email">{{ __('Email') }}</label>
                    <input class="sa-input" id="new-email" name="email" type="email" required maxlength="255" autocomplete="off" value="{{ old('email') }}">
                    <label class="sa-label" for="new-password">{{ __('Initial password (at least 12 characters)') }}</label>
                    <input class="sa-input" id="new-password" name="password" type="password" required minlength="12" maxlength="255" autocomplete="new-password">
                    <label class="sa-label" for="new-password-confirmation">{{ __('Confirm password') }}</label>
                    <input class="sa-input" id="new-password-confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="255" autocomplete="new-password">
                    <input type="hidden" name="sync_groups" value="1">
                    <fieldset class="sa-mt-4">
                        <legend>{{ __('Groups') }}</legend>
                        @foreach ($groups as $group)
                            <label class="sa-label">
                                <input type="checkbox" name="group_ids[]" value="{{ $group->id }}">
                                {{ $group->name }}
                            </label>
                        @endforeach
                    </fieldset>
                    <button class="sa-btn sa-mt-4" type="submit">{{ __('Create account') }}</button>
                </form>
            </section>
        @endif
    @endcan

    @forelse ($users as $staff)
        <article class="sa-section">
            <h2>{{ $staff['name'] }}</h2>
            <p>{{ $staff['username'] }} &middot; {{ $staff['email'] }}</p>
            @can('secure-access', [$updatePermission])
                @if ($canUpdate)
                    <form method="POST" action="{{ route('secure-access.staff.update', $staff['id']) }}">
                        @csrf
                        @method('PUT')
                        <label class="sa-label" for="staff-{{ $staff['id'] }}-name">{{ __('Name') }}</label>
                        <input class="sa-input" id="staff-{{ $staff['id'] }}-name" name="name" value="{{ $staff['name'] }}" required maxlength="255">
                        <label class="sa-label" for="staff-{{ $staff['id'] }}-username">{{ __('Username') }}</label>
                        <input class="sa-input" id="staff-{{ $staff['id'] }}-username" name="username" value="{{ $staff['username'] }}" required maxlength="255" autocomplete="off">
                        <label class="sa-label" for="staff-{{ $staff['id'] }}-email">{{ __('Email') }}</label>
                        <input class="sa-input" id="staff-{{ $staff['id'] }}-email" name="email" type="email" value="{{ $staff['email'] }}" required maxlength="255" autocomplete="off">
                        @can('secure-access', [$credentialPermission])
                            @if ($canResetPassword)
                                <label class="sa-label" for="staff-{{ $staff['id'] }}-password">{{ __('New password (leave blank to keep unchanged)') }}</label>
                                <input class="sa-input" id="staff-{{ $staff['id'] }}-password" name="password" type="password" minlength="12" maxlength="255" autocomplete="new-password">
                                <label class="sa-label" for="staff-{{ $staff['id'] }}-password-confirmation">{{ __('Confirm new password') }}</label>
                                <input class="sa-input" id="staff-{{ $staff['id'] }}-password-confirmation" name="password_confirmation" type="password" minlength="12" maxlength="255" autocomplete="new-password">
                            @endif
                        @endcan
                        @can('secure-access', [$assignPermission])
                            @if ($canAssign)
                                <input type="hidden" name="sync_groups" value="1">
                                <fieldset class="sa-mt-4">
                                    <legend>{{ __('Groups') }}</legend>
                                    @foreach ($groups as $group)
                                        <label class="sa-label">
                                            <input type="checkbox" name="group_ids[]" value="{{ $group->id }}" @checked(in_array($group->id, $staff['group_ids']))>
                                            {{ $group->name }}
                                        </label>
                                    @endforeach
                                </fieldset>
                            @endif
                        @endcan
                        <button class="sa-btn sa-mt-4" type="submit">{{ __('Save account') }}</button>
                    </form>
                @endif
            @endcan
            @can('secure-access', [$deletePermission])
                @if ($canDelete && $staff['id'] !== $actorId)
                    <form class="sa-mt-4" method="POST" action="{{ route('secure-access.staff.destroy', $staff['id']) }}">
                        @csrf
                        @method('DELETE')
                        <button class="sa-btn sa-btn-danger" type="submit">{{ __('Delete account') }}</button>
                    </form>
                @endif
            @endcan
        </article>
    @empty
        <p>{{ __('No accounts available.') }}</p>
    @endforelse

    {{ $users->links() }}
@endsection
