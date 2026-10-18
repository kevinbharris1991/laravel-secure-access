@extends('secure-access::layouts.app')

@section('title', __('Representative workspace'))
@section('header', __('Representative workspace'))

@section('content')
    <div class="sa-section">
        <p class="sa-text">{{ __('Use your own account. Only explicitly verified actions are available; this does not change the represented person’s ordinary access or grant access to their private conversations.') }}</p>
        @forelse ($relationships as $entry)
            <h2 class="sa-title">{{ __('Acting on behalf of: :name', ['name' => $entry['adult']->name]) }}</h2>
            <ul class="sa-list">
                @foreach (collect($entry['scopes'])->groupBy(fn ($scope) => explode('.', $scope)[0]) as $module => $scopes)
                    <li>
                        @if ($scopes->contains($module.'.read') || $scopes->contains($module.'.create'))
                            <a class="sa-link" href="{{ route('secure-access.representative.module', [$entry['relationship'], $module]) }}">{{ \SecureAccess\Camp\Modules::get($module)['title'] }}</a>
                        @else
                            {{ \SecureAccess\Camp\Modules::get($module)['title'] }}
                        @endif
                        — {{ $scopes->map(fn ($scope) => explode('.', $scope)[1])->join(', ') }}
                    </li>
                @endforeach
            </ul>
        @empty
            <p class="sa-text">{{ __('No currently effective representative permissions.') }}</p>
        @endforelse
    </div>
@endsection
