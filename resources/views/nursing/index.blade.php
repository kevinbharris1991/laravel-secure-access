@extends('secure-access::layouts.app')

@section('title', __('Nursing teams'))
@section('header', __('Nursing teams'))

@section('content')
    <section class="sa-section">
        <p class="sa-text">{{ __('Assign an existing nursing staff member to a self advocate’s team. This does not grant representative authority or change account roles. The assigning director becomes a team messaging contact.') }}</p>
        <p class="sa-text">{{ __('One current nurse receives published healthcare disclosures for each self-advocate. Reassignment removes the previous recipient’s disclosure access. Nursing membership alone does not grant access; private drafts, original evidence, financial summaries, and review history are never disclosed.') }}</p>
        @if ($errors->any())
            <ul role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('secure-access.nursing.store') }}">
            @csrf
            <label class="sa-label" for="self_advocate_id">{{ __('Self advocate') }}</label>
            <select class="sa-input" id="self_advocate_id" name="self_advocate_id" required>
                <option value="">{{ __('Select a self advocate') }}</option>
                @foreach ($adults as $adult)
                    <option value="{{ $adult['id'] }}" @selected(old('self_advocate_id') === $adult['id'])>{{ $adult['name'] }} (#{{ $adult['id'] }})</option>
                @endforeach
            </select>
            <label class="sa-label" for="nurse_id">{{ __('Nursing staff member') }}</label>
            <select class="sa-input" id="nurse_id" name="nurse_id" required>
                <option value="">{{ __('Select a nurse') }}</option>
                @foreach ($nurses as $nurse)
                    <option value="{{ $nurse['id'] }}" @selected(old('nurse_id') === $nurse['id'])>{{ $nurse['name'] }} (#{{ $nurse['id'] }})</option>
                @endforeach
            </select>
            <button class="sa-btn" type="submit">{{ __('Assign nurse') }}</button>
        </form>
    </section>
    <section class="sa-section">
        <h2>{{ __('Active nursing assignments') }}</h2>
        @forelse ($assignments as $assignment)
            <p>{{ __('Team relationship #:id', ['id' => $assignment->id]) }}:
                {{ __('Self advocate') }} #{{ $assignment->self_advocate_id }}
                {{ $adults->firstWhere('id', $assignment->self_advocate_id)['name'] ?? '' }} —
                {{ __('Nurse') }} #{{ $assignment->team_member_id }}
                {{ $nurses->firstWhere('id', $assignment->team_member_id)['name'] ?? '' }} —
                {{ __('Assigning director') }} #{{ $assignment->nurse_assigned_by }}
            </p>
            <form method="POST" action="{{ route('secure-access.nursing.destroy', $assignment) }}">
                @csrf
                @method('DELETE')
                <p class="sa-text">{{ __('Removing this nurse ends the entire website team relationship, including any existing voluntary relationship and delegated website access. This does not terminate legal authority. Files and history are retained.') }}</p>
                <button class="sa-btn" type="submit">{{ __('Remove nurse') }}</button>
            </form>
        @empty
            <p>{{ __('No active assignments.') }}</p>
        @endforelse
        <a class="sa-link" href="{{ route('secure-access.messaging.index') }}">{{ __('Team messages') }}</a>
    </section>
    <section class="sa-section">
        <h2>{{ __('Assignment history') }}</h2>
        @foreach ($audits as $audit)
            <p>#{{ $audit->team_member_id }} — {{ $audit->action }} — {{ __('Director') }}
                #{{ $audit->actor_id }} — {{ $audit->created_at }}</p>
        @endforeach
        {{ $audits->links() }}
    </section>
@endsection
