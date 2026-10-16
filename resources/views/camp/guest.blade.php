@extends('secure-access::layouts.app')

@section('title', __('Guest portal'))
@section('header', __('My camp stay'))

@section('content')
    <div class="sa-section">
        <a class="sa-link" href="{{ route('secure-access.dashboard') }}">{{ __('Dashboard') }}</a>
    </div>

    <section class="sa-section" aria-labelledby="guest-meals">
        <h3 id="guest-meals">{{ __('Meals and my substitutions') }}</h3>
        <p>{{ __('Meal substitutions are managed by catering staff. Contact staff to request a change.') }}</p>
        @forelse ($meals as $meal)
            <article>
                <h4>{{ $meal['date'] }} — {{ __(ucfirst($meal['period'])) }}</h4>
                @if ($meal['incompatible'])
                    <p><s>{{ $meal['name'] }}</s> <span>{{ __('Not compatible with your dietary needs') }}</span></p>
                @else
                    <p>{{ $meal['name'] }}</p>
                @endif
                @if ($meal['allergens'] !== '')
                    <p>{{ __('Allergens') }}: {{ $meal['allergens'] }}</p>
                @endif
                @foreach ($meal['substitutions'] as $substitution)
                    <p>{{ __('Your substitution') }}: {{ $substitution }}</p>
                @endforeach
            </article>
        @empty
            <p>{{ __('No public meals are available.') }}</p>
        @endforelse
    </section>

    @foreach (['activities' => __('Activities'), 'events' => __('Events')] as $module => $title)
        <section class="sa-section" aria-labelledby="guest-{{ $module }}">
            <h3 id="guest-{{ $module }}">{{ $title }}</h3>
            @forelse ($opportunities[$module] as $opportunity)
                <article>
                    <h4>{{ $opportunity->data['name'] ?? $opportunity->data['title'] ?? '' }}</h4>
                    <p>{{ $opportunity->data['date'] ?? $opportunity->data['scheduled_at'] ?? '' }} {{ $opportunity->data['description'] ?? '' }}</p>
                    @if ($commitments->contains(fn ($commitment) => ($commitment->data['target_module'] ?? null) === $module && (string) ($commitment->data['target_id'] ?? '') === (string) $opportunity->id))
                        <p>{{ __('You are enrolled') }}</p>
                    @elseif ($canCommit[$module])
                        @can('secure-access', [$module.'.commit_public', $opportunity])
                            <form method="POST" action="{{ route('secure-access.guest.commit', ['module' => $module, 'record' => $opportunity->id]) }}">
                                @csrf
                                <button class="sa-btn" type="submit">{{ __('Enroll') }}</button>
                            </form>
                        @endcan
                    @endif
                </article>
            @empty
                <p>{{ __('No published opportunities are available.') }}</p>
            @endforelse
        </section>
    @endforeach

    <section class="sa-section" aria-labelledby="guest-enrollments">
        <h3 id="guest-enrollments">{{ __('My enrollments') }}</h3>
        @forelse ($commitments as $commitment)
            <p>{{ $commitment->data['name'] ?? '' }}</p>
        @empty
            <p>{{ __('You have no enrollments yet.') }}</p>
        @endforelse
    </section>
@endsection
