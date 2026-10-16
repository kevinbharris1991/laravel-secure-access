@extends('secure-access::layouts.app')

@section('title', __('Dashboard'))
@section('header', __('Dashboard'))

@section('content')
    <div class="sa-section">
        <h2 class="sa-title">{{ __('Welcome to your dashboard') }}</h2>
        <p class="sa-text">{{ __('Your available tools reflect the permissions granted by your current groups.') }}</p>

        @if ($groups->isNotEmpty())
            <h3 class="sa-title">{{ __('Your groups') }}</h3>
            <ul class="sa-list">
                @foreach ($groups as $group)
                    <li>{{ $group->name }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @forelse ($items as $item)
        <div class="sa-section">
            <h2 class="sa-title">{{ $item['title'] }}</h2>
            <p class="sa-text">{{ $item['description'] }}</p>
            <div class="sa-mt-4">
                <a class="sa-btn" href="{{ $item['url'] }}">{{ $item['title'] }}</a>
            </div>
        </div>
    @empty
        <div class="sa-section">
            <p class="sa-text">{{ __('No group tools are available for your account yet.') }}</p>
        </div>
    @endforelse

    <div class="sa-section">
        <h2 class="sa-title">{{ __('Account security') }}</h2>
        <p class="sa-text">{{ __('Manage your multi-factor authentication methods and sign-in email addresses.') }}</p>
        <div class="sa-mt-4">
            <a class="sa-btn" href="{{ route('secure-access.security') }}">{{ __('Manage security') }}</a>
        </div>
    </div>
@endsection
