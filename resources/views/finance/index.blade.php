@extends('secure-access::layouts.app')

@section('title', __('Finance assignments'))
@section('header', __('Finance assignments'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('status'))
        <p class="sa-text" role="status">{{ session('status') }}</p>
    @endif
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Assign financial staff') }}</h2>
        <p class="sa-text">{{ __('Choose one current Finance & Accounting staff member for this self-advocate. Reassignment removes the previous recipient’s disclosure access. Assignment does not grant representative authority, change account roles, or expose original documents or healthcare information. Only explicitly published financial disclosures within their dates are available.') }}</p>
        <form method="POST" action="{{ route('secure-access.finance.store') }}">
            @csrf
            <label class="sa-label" for="self-advocate">{{ __('Self-advocate') }}</label>
            <select class="sa-input" id="self-advocate" name="self_advocate_id" required>
                <option value="">{{ __('Select a self-advocate') }}</option>
                @foreach ($adults as $adult)
                    <option value="{{ $adult['id'] }}" @selected(old('self_advocate_id') === $adult['id'])>{{ $adult['name'] }} (#{{ $adult['id'] }})</option>
                @endforeach
            </select>
            <label class="sa-label" for="officer">{{ __('Finance & Accounting staff member') }}</label>
            <select class="sa-input" id="officer" name="officer_id" required>
                <option value="">{{ __('Select financial staff') }}</option>
                @foreach ($officers as $officer)
                    <option value="{{ $officer['id'] }}" @selected(old('officer_id') === $officer['id'])>{{ $officer['name'] }} (#{{ $officer['id'] }})</option>
                @endforeach
            </select>
            <button class="sa-btn sa-mt-4" type="submit">{{ __('Assign financial staff') }}</button>
        </form>
    </section>
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Current financial assignments') }}</h2>
        @forelse ($assignments as $assignment)
            <div class="sa-option">
                <p class="sa-text">{{ __('Self-advocate') }} #{{ $assignment->ward_id }} {{ $adults->firstWhere('id', $assignment->ward_id)['name'] ?? '' }} —
                    {{ __('Financial staff') }} #{{ $assignment->assignee_id }} {{ $officers->firstWhere('id', $assignment->assignee_id)['name'] ?? '' }}</p>
                <form method="POST" action="{{ route('secure-access.finance.destroy', $assignment) }}">
                    @csrf
                    @method('DELETE')
                    <button class="sa-btn" type="submit">{{ __('Remove financial assignment') }}</button>
                </form>
            </div>
        @empty
            <p class="sa-text">{{ __('No current financial assignments.') }}</p>
        @endforelse
    </section>
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Assignment history') }}</h2>
        @forelse ($audits as $audit)
            <p class="sa-text">#{{ $audit->assignment_id }} — {{ __(ucfirst($audit->action)) }} — {{ __('Director') }} #{{ $audit->actor_id }} — {{ $audit->created_at }}</p>
        @empty
            <p class="sa-text">{{ __('No assignment changes yet.') }}</p>
        @endforelse
        {{ $audits->links() }}
    </section>
@endsection
