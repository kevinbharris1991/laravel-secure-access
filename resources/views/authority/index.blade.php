@extends('secure-access::layouts.app')

@section('title', __('Authority document review'))
@section('header', __('Authority document review'))

@section('content')
    <p class="sa-text">{{ __('Independent Administrative Directors review submitted evidence. Confirmation does not establish legal authority. Review decisions and lifecycle status are separate; only confirmed, active documents within their dates can support explicitly selected website capabilities.') }}</p>
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Pending termination-evidence reviews') }}</h2>
        @forelse ($evidenceQueue as $evidence)
            <p class="sa-text"><a class="sa-link" href="{{ route('secure-access.authority.evidence.show', $evidence) }}">{{ __('Evidence #:id', ['id' => $evidence->getKey()]) }}</a></p>
        @empty
            <p class="sa-text">{{ __('No termination evidence awaiting review.') }}</p>
        @endforelse
        {{ $evidenceQueue->links() }}
    </section>
    <form method="GET" action="{{ route('secure-access.authority.index') }}">
        <label class="sa-label" for="authority-status">{{ __('Filter by review decision or lifecycle') }}</label>
        <select class="sa-input" id="authority-status" name="status">
            <option value="all" @selected($status === 'all')>{{ __('All documents') }}</option>
            <optgroup label="{{ __('Review decision') }}">
                @foreach (\SecureAccess\Models\TeamAuthorityDocument::REVIEW_DECISIONS as $filter)
                    <option value="{{ $filter }}" @selected($status === $filter)>{{ __(ucfirst($filter)) }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('Lifecycle status') }}">
                @foreach (\SecureAccess\Models\TeamAuthorityDocument::LIFECYCLE_STATUSES as $filter)
                    <option value="{{ $filter }}" @selected($status === $filter)>{{ $filter === 'revoked' ? __('Verified termination recorded') : ($filter === 'suspended' ? __('Website access suspended') : __(ucfirst($filter))) }}</option>
                @endforeach
            </optgroup>
        </select>
        <button class="sa-btn" type="submit">{{ __('Filter') }}</button>
    </form>
    <section class="sa-section">
        @forelse ($documents as $document)
            <p class="sa-text">
                <a class="sa-link" href="{{ route('secure-access.authority.show', $document) }}">{{ __('Document #:id', ['id' => $document->getKey()]) }}</a>
                — {{ __(ucwords(str_replace('_', ' ', $document->type))) }}
                — {{ $document->statusLabel() }}
                — {{ __('Review') }}: {{ __(ucfirst($document->review_decision)) }}
                — {{ __('Lifecycle') }}: {{ __(ucfirst($document->lifecycle_status)) }}
            </p>
        @empty
            <p class="sa-text">{{ __('No documents match this filter.') }}</p>
        @endforelse
        {{ $documents->links() }}
    </section>
@endsection
