@extends('secure-access::layouts.app')

@section('title', __('Dashboard'))
@section('header', __('Dashboard'))

@section('content')
    @if ($canReviewAuthority)
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Authority document review') }}</h2>
            <a class="sa-btn" href="{{ route('secure-access.authority.index') }}">{{ __('Review queue & history') }}</a>
        </div>
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Nursing teams') }}</h2>
            <a class="sa-btn" href="{{ route('secure-access.nursing.index') }}">{{ __('Assign or remove a nurse') }}</a>
        </div>
    @endif
    @if ($canViewHealthcareAuthority)
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Shared healthcare authority') }}</h2>
            <a class="sa-btn" href="{{ route('secure-access.authority.shares.department', 'medical-nursing') }}">{{ __('Open Medical/Nursing shares') }}</a>
        </div>
    @endif
    @if ($canViewFinancialAuthority)
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Shared financial authority') }}</h2>
            <a class="sa-btn" href="{{ route('secure-access.authority.shares.department', 'finance-accounting') }}">{{ __('Open Finance shares') }}</a>
        </div>
    @endif
    @if (in_array(\SecureAccess\SecureAccess::guard()->user()?->registration_role, ['family_caregiver', 'professional'], true))
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Representative access') }}</h2>
            <a class="sa-btn" href="{{ route('secure-access.representative.index') }}">{{ __('Act on behalf of someone') }}</a>
        </div>
    @endif
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

    @can('secure-access', ['meal_plans.read_public'])
        <div class="sa-section">
            <h2 class="sa-title">{{ __('My stay') }}</h2>
            <p class="sa-text">{{ __('See your meal alternatives, browse activities, and review your commitments.') }}</p>
            <a class="sa-btn sa-mt-4" href="{{ route('secure-access.guest.index') }}">{{ __('Meals & activities') }}</a>
        </div>
    @endcan

    @if ($staffPermission)
        @can('secure-access', [$staffPermission])
            <div class="sa-section">
                <h2 class="sa-title">{{ $staffPermission === 'executive_users.manage_all' ? __('Executive accounts') : __('Staff accounts') }}</h2>
                <a class="sa-btn sa-mt-4" href="{{ route('secure-access.staff.index') }}">{{ __('Manage accounts') }}</a>
            </div>
        @endcan
    @endif

    @if ($canManageTeam)
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Your team') }}</h2>
            <p class="sa-text">{{ __('Invite and manage your loved ones, clients, or staff members.') }}</p>
            <a class="sa-btn sa-mt-4" href="{{ route('secure-access.team.index') }}">{{ __('Manage your team') }}</a>
        </div>
    @endif

    @if ($campItems)
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Your camp modules') }}</h2>
            <div class="sa-module-grid sa-mt-4">
                @foreach ($campItems as $module => $item)
                    @can('secure-access', [$item['permission'], $item['subject']])
                        <div class="sa-option">
                            <strong>{{ $item['title'] }}</strong>
                            <p class="sa-text">{{ __('Open your authorized records and actions') }}</p>
                            <a class="sa-link" href="{{ route('secure-access.camp.index', $module) }}">{{ __('Open module') }}</a>
                            @php($createPermission = \SecureAccess\Camp\Modules::permission(\SecureAccess\SecureAccess::guard()->user(), $module, 'create'))
                            @if ($createPermission)
                                @can('secure-access', [$createPermission, $item['subject']])
                                    <a class="sa-btn sa-mt-4" href="{{ route('secure-access.camp.create', $module) }}">{{ __('Create') }}</a>
                                @endcan
                            @endif
                        </div>
                    @endcan
                @endforeach
            </div>
        </div>
    @endif

    @forelse ($items as $item)
        <div class="sa-section">
            <h2 class="sa-title">{{ $item['title'] }}</h2>
            <p class="sa-text">{{ $item['description'] }}</p>
            <div class="sa-mt-4">
                <a class="sa-btn" href="{{ $item['url'] }}">{{ $item['title'] }}</a>
            </div>
        </div>
    @empty
        @if (! $campItems)
        <div class="sa-section">
            <p class="sa-text">{{ __('No group tools are available for your account yet.') }}</p>
        </div>
        @endif
    @endforelse

    <div class="sa-section">
        <h2 class="sa-title">{{ __('Account security') }}</h2>
        <p class="sa-text">{{ __('Manage your multi-factor authentication methods and sign-in email addresses.') }}</p>
        <div class="sa-mt-4">
            <a class="sa-btn" href="{{ route('secure-access.security') }}">{{ __('Manage security') }}</a>
        </div>
    </div>
@endsection
