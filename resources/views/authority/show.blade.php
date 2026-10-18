@extends('secure-access::layouts.app')

@section('title', __('Authority document'))
@section('header', __('Authority document'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('status'))
        <p class="sa-text">{{ session('status') }}</p>
    @endif
    <section class="sa-section">
        <h2 class="sa-title">{{ __(ucwords(str_replace('_', ' ', $document->type))) }}</h2>
        <p class="sa-text">{{ __('Status') }}: {{ __(ucwords(str_replace('_', ' ', $document->status))) }}</p>
        <p class="sa-text">{{ __('Submission does not grant authority. Only approved capabilities within their effective dates permit representative access.') }}</p>
        <a class="sa-link" href="{{ route('secure-access.team.authority-documents.download', [$document->team_member_id, $document]) }}">{{ __('Download evidence') }}</a>
        @if ($document->previous_document_id)
            <p><a class="sa-link" href="{{ route('secure-access.authority.show', $document->previous_document_id) }}">{{ __('Previous version') }}</a></p>
        @endif
        @if ($document->approved_scopes)
            <h3 class="sa-title">{{ __('Approved capabilities') }}</h3>
            <ul>@foreach ($document->approved_scopes as $scope)<li>{{ $scope }}</li>@endforeach</ul>
            <p class="sa-text">{{ __('Effective') }}: {{ $document->effective_at?->toDateTimeString() }} — {{ __('Expires') }}: {{ $document->expires_at?->toDateTimeString() }}</p>
        @endif
    </section>
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Review history') }}</h2>
        @forelse ($history as $review)
            <p class="sa-text">{{ __(ucwords(str_replace('_', ' ', $review['decision']))) }} — {{ $review['reviewed_at'] }}</p>
            <p class="sa-text" style="white-space: pre-wrap">{{ $review['comments'] }}</p>
        @empty
            <p class="sa-text">{{ __('No reviews yet.') }}</p>
        @endforelse
    </section>
    @if ($canReview && in_array($document->status, ['pending', 'approved'], true))
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Record decision') }}</h2>
            <form method="POST" action="{{ route('secure-access.authority.update', $document) }}">
                @csrf
                <label class="sa-label" for="decision">{{ __('Decision') }}</label>
                <select class="sa-input" id="decision" name="decision" required>
                    @foreach ($document->status === 'approved' ? ['revoked'] : ['approved', 'rejected', 'needs_information'] as $decision)
                        <option value="{{ $decision }}" @selected(old('decision') === $decision)>{{ __(ucwords(str_replace('_', ' ', $decision))) }}</option>
                    @endforeach
                </select>
                @if ($document->status === 'pending')
                    <p class="sa-text">{{ __('For approval, select only the capabilities supported by the evidence and specify effective and expiry dates.') }}</p>
                    @foreach ($capabilities as $domain => $scopes)
                        <fieldset class="sa-section">
                            <legend>{{ __(ucwords(str_replace('_', ' ', $domain))) }}</legend>
                            @foreach ($scopes as $scope)
                                <label class="sa-label"><input type="checkbox" name="approved_scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('approved_scopes', []), true))> {{ $scope }}</label>
                            @endforeach
                        </fieldset>
                    @endforeach
                    <label class="sa-label" for="effective-at">{{ __('Effective at') }}</label>
                    <input class="sa-input" type="datetime-local" id="effective-at" name="effective_at" value="{{ old('effective_at') }}">
                    <label class="sa-label" for="expires-at">{{ __('Expires at') }}</label>
                    <input class="sa-input" type="datetime-local" id="expires-at" name="expires_at" value="{{ old('expires_at') }}">
                @endif
                <label class="sa-label" for="comments">{{ __('Comments / reason (required; visible only to reviewers and the relationship participants)') }}</label>
                <textarea class="sa-input" id="comments" name="comments" required maxlength="10000">{{ old('comments') }}</textarea>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Record decision') }}</button>
            </form>
        </section>
    @endif
    @if ($canResubmit)
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Submit a new version') }}</h2>
            <p class="sa-text">{{ __('The original evidence and review history will be retained.') }}</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.team.authority-documents.store', $document->team_member_id) }}">
                @csrf
                <input type="hidden" name="previous_document_id" value="{{ $document->getKey() }}">
                <input type="hidden" name="type" value="{{ $document->type }}">
                <label class="sa-label" for="replacement">{{ __('New evidence (PDF, JPG or PNG; max 10 MB)') }}</label>
                <input class="sa-input" id="replacement" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Submit new version') }}</button>
            </form>
        </section>
    @endif
@endsection
