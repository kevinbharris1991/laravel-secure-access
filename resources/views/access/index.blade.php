@extends('secure-access::layouts.guest')

@section('title', __('Access management'))

@section('content')
    <h1 class="sa-title">{{ __('Access management') }}</h1>

    @if ($errors->any())
        <div role="alert">
            @include('secure-access::partials.errors', ['messages' => $errors->all()])
        </div>
    @endif

    <section class="sa-mt-6" aria-labelledby="groups-heading">
        <h2 id="groups-heading" class="sa-title">{{ __('Groups') }}</h2>

        @if ($canManageGroups)
            <form method="POST" action="{{ route('secure-access.access.groups.store') }}">
                @csrf
                <label class="sa-label" for="new-group-key">{{ __('Group key') }}</label>
                <input class="sa-input" id="new-group-key" name="key" required maxlength="255" pattern="[a-z0-9][a-z0-9._-]*">
                <label class="sa-label" for="new-group-name">{{ __('Group name') }}</label>
                <input class="sa-input" id="new-group-name" name="name" required maxlength="255">
                <button class="sa-btn" type="submit">{{ __('Create group') }}</button>
            </form>
        @endif

        @foreach ($groups as $group)
            <article class="sa-mt-6">
                <h3>{{ $group->name }} <small>{{ $group->key }}</small></h3>
                <ul>
                    @forelse ($group->permissions as $grant)
                        <li>{{ $grant->key }} ({{ $grant->scope }})</li>
                    @empty
                        <li>{{ __('No permissions granted.') }}</li>
                    @endforelse
                </ul>

                @if ($canManageGroups)
                    <form method="POST" action="{{ route('secure-access.access.groups.update', $group) }}">
                        @csrf
                        @method('PUT')
                        <label class="sa-label" for="group-key-{{ $group->id }}">{{ __('Group key') }}</label>
                        <input class="sa-input" id="group-key-{{ $group->id }}" name="key" value="{{ $group->key }}" required maxlength="255" pattern="[a-z0-9][a-z0-9._-]*" @readonly(in_array($group->key, ['executive-director', 'guest'], true))>
                        <label class="sa-label" for="group-name-{{ $group->id }}">{{ __('Group name') }}</label>
                        <input class="sa-input" id="group-name-{{ $group->id }}" name="name" value="{{ $group->name }}" required maxlength="255">
                        <button class="sa-btn" type="submit">{{ __('Save group') }}</button>
                    </form>
                    @if (! in_array($group->key, ['executive-director', 'guest'], true) && ($canManagePermissions || $group->permissions->isEmpty()))
                        <form class="sa-mt-4" method="POST" action="{{ route('secure-access.access.groups.destroy', $group) }}">
                            @csrf
                            @method('DELETE')
                            <button class="sa-btn sa-btn-danger" type="submit">{{ __('Delete group') }}</button>
                        </form>
                    @endif
                @endif

                @if ($canManageGroups && $canManagePermissions)
                    <form class="sa-mt-4" method="POST" action="{{ route('secure-access.access.groups.permissions', $group) }}">
                        @csrf
                        @method('PUT')
                        <fieldset>
                            <legend>{{ __('Group permissions') }}</legend>
                            @foreach ($permissions as $permission)
                                <label class="sa-label">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($group->permissions->contains($permission->id))>
                                    {{ $permission->key }} ({{ $permission->scope }})
                                </label>
                            @endforeach
                        </fieldset>
                        <button class="sa-btn" type="submit">{{ __('Save grants') }}</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>

    <section class="sa-mt-6" aria-labelledby="permissions-heading">
        <h2 id="permissions-heading" class="sa-title">{{ __('Permissions') }}</h2>

        @if ($canManagePermissions)
            <form method="POST" action="{{ route('secure-access.access.permissions.store') }}">
                @csrf
                <label class="sa-label" for="new-permission-key">{{ __('Permission key') }}</label>
                <input class="sa-input" id="new-permission-key" name="key" required maxlength="255" pattern="[a-z0-9][a-z0-9._-]*">
                <label class="sa-label" for="new-permission-scope">{{ __('Scope') }}</label>
                <select class="sa-input" id="new-permission-scope" name="scope" required>
                    @foreach (['all', 'own', 'assigned'] as $scope)
                        <option value="{{ $scope }}">{{ $scope }}</option>
                    @endforeach
                </select>
                <button class="sa-btn" type="submit">{{ __('Create permission') }}</button>
            </form>
        @endif

        @foreach ($permissions as $permission)
            <article class="sa-mt-6">
                <h3>{{ $permission->key }} ({{ $permission->scope }})</h3>
                @if ($canManagePermissions)
                    <form method="POST" action="{{ route('secure-access.access.permissions.update', $permission) }}">
                        @csrf
                        @method('PUT')
                        <label class="sa-label" for="permission-key-{{ $permission->id }}">{{ __('Permission key') }}</label>
                        <input class="sa-input" id="permission-key-{{ $permission->id }}" name="key" value="{{ $permission->key }}" required maxlength="255" pattern="[a-z0-9][a-z0-9._-]*">
                        <label class="sa-label" for="permission-scope-{{ $permission->id }}">{{ __('Scope') }}</label>
                        <select class="sa-input" id="permission-scope-{{ $permission->id }}" name="scope" required>
                            @foreach (['all', 'own', 'assigned'] as $scope)
                                <option value="{{ $scope }}" @selected($permission->scope === $scope)>{{ $scope }}</option>
                            @endforeach
                        </select>
                        <button class="sa-btn" type="submit">{{ __('Save permission') }}</button>
                    </form>
                    <form class="sa-mt-4" method="POST" action="{{ route('secure-access.access.permissions.destroy', $permission) }}">
                        @csrf
                        @method('DELETE')
                        <button class="sa-btn sa-btn-danger" type="submit">{{ __('Delete permission') }}</button>
                    </form>
                @endif
            </article>
        @endforeach
    </section>
@endsection
