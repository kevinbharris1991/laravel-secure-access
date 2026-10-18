@extends('secure-access::layouts.app')

@section('title', __('Termination evidence'))
@section('header', __('Termination evidence'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('status'))
        <p class="sa-text">{{ session('status') }}</p>
    @endif
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Evidence #:id', ['id' => $evidence->getKey()]) }}</h2>
        <p class="sa-text">{{ __('Status') }}: {{ __(ucwords(str_replace('_', ' ', $evidence->status))) }}</p>
        <p class="sa-text">{{ __('Submitting evidence does not change access or revoke legal authority. Acceptance records an independently verified legal termination and ends only the representative website permissions granted by the original document. The adult’s ordinary permissions remain unchanged.') }}</p>
        <p><a class="sa-link" href="{{ route('secure-access.authority.show', $evidence->document) }}">{{ __('Original authority document') }}</a></p>
        <p><a class="sa-link" href="{{ route('secure-access.authority.evidence.download', $evidence) }}">{{ __('Download evidence') }}</a></p>
        @if ($evidence->comments)
            <h3 class="sa-title">{{ __('Submitter comments') }}</h3>
            <p class="sa-text" style="white-space: pre-wrap">{{ $evidence->comments }}</p>
        @endif
        @if ($canViewHistory ?? false)
            <h3 class="sa-title">{{ __('Evidence review history') }}</h3>
            @forelse ($evidence->review_history ?? [] as $review)
                <p class="sa-text">{{ __(ucwords(str_replace('_', ' ', $review['decision']))) }} — {{ $review['reviewed_at'] }}</p>
                <p class="sa-text" style="white-space: pre-wrap">{{ $review['comments'] }}</p>
            @empty
                <p class="sa-text">{{ __('No evidence review yet.') }}</p>
            @endforelse
        @endif
        @if (in_array($evidence->status, ['rejected', 'needs_information'], true))
            <p class="sa-text">{{ __('Submit a new evidence file from the original document page for further review. Existing evidence and decisions are retained.') }}</p>
        @endif
    </section>
    @if ($canReview)
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Independent evidence verification') }}</h2>
            <form method="POST" action="{{ route('secure-access.authority.evidence.update', $evidence) }}">
                @csrf
                <label class="sa-label" for="evidence-decision">{{ __('Decision') }}</label>
                <select class="sa-input" id="evidence-decision" name="decision" required>
                    <option value="needs_information">{{ __('Request more information; leave permissions unchanged') }}</option>
                    <option value="rejected">{{ __('Reject evidence; leave permissions unchanged') }}</option>
                    <option value="accepted">{{ __('Accept verified evidence and record termination') }}</option>
                </select>
                <label class="sa-label" for="review-comments">{{ __('Verification comments / reason (required)') }}</label>
                <textarea class="sa-input" id="review-comments" name="comments" required maxlength="10000">{{ old('comments') }}</textarea>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Record evidence verification') }}</button>
            </form>
        </section>
    @endif
@endsection
