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
        <p class="sa-text">{{ __('Status') }}: {{ $document->statusLabel() }}</p>
        @if ($document->status === 'suspended')
            <p class="sa-text">{{ __('Website representative access is suspended. This is not a determination that legal authority has been revoked or terminated.') }}</p>
        @endif
        <p class="sa-text">{{ __('Submission does not grant authority. Only approved capabilities within their effective dates permit representative access.') }}</p>
        <a class="sa-link" href="{{ route('secure-access.team.authority-documents.download', [$document->team_member_id, $document]) }}">{{ __('Download evidence') }}</a>
        @if ($document->previous_document_id)
            <p><a class="sa-link" href="{{ route('secure-access.authority.show', $document->previous_document_id) }}">{{ __('Previous version') }}</a></p>
        @endif
        @if ($document->approved_scopes)
            <h3 class="sa-title">{{ __('Approved capabilities') }}</h3>
            <ul>@foreach ($document->approved_scopes as $scope)<li>{{ $scope }}</li>@endforeach</ul>
            <p class="sa-text">{{ __('Effective') }}: {{ $document->effective_at?->toDateTimeString() }} — {{ __('Expires') }}: {{ $document->expires_at?->toDateTimeString() ?? __('No expiry') }}</p>
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
    @if ($canReview && $document->status === 'pending')
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Record decision') }}</h2>
            <form method="POST" action="{{ route('secure-access.authority.update', $document) }}">
                @csrf
                <label class="sa-label" for="decision">{{ __('Decision') }}</label>
                <select class="sa-input" id="decision" name="decision" required>
                    @foreach (['approved', 'rejected', 'needs_information'] as $decision)
                        <option value="{{ $decision }}" @selected(old('decision') === $decision)>{{ __(ucwords(str_replace('_', ' ', $decision))) }}</option>
                    @endforeach
                </select>
                @if ($document->status === 'pending')
                    <p class="sa-text">{{ __('For approval, select only the capabilities supported by the evidence and specify the effective date. Set an expiry date if the authority is time-limited; otherwise leave expiry blank.') }}</p>
                    <p class="sa-text">{{ __('A replacement for currently active authority must take effect immediately so existing access is not interrupted.') }}</p>
                    <p class="sa-text">{{ __('Select the same module’s read capability for update, payment, cancellation, submission, or commitment actions so the representative can open the record. No capabilities are added automatically.') }}</p>
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
                    <label class="sa-label" for="expires-at">{{ __('Expires at (optional)') }}</label>
                    <input class="sa-input" type="datetime-local" id="expires-at" name="expires_at" value="{{ old('expires_at') }}">
                @endif
                <section class="sa-section">
                    <h2 class="sa-title">{{ __('Evidence of legal revocation or termination') }}</h2>
                    <p class="sa-text">{{ __('This website cannot revoke guardianship, conservatorship, or power of attorney. Parties may submit evidence that legal authority has ended. Submission alone does not change access. An independent Administrative Director must verify the evidence before updating the records and ending this document’s representative permissions. The represented adult’s ordinary permissions are never removed.') }}</p>
                    @forelse ($document->revocationEvidence->sortByDesc('id') as $evidence)
                        <p class="sa-text">
                            <a class="sa-link" href="{{ route('secure-access.authority.evidence.show', $evidence) }}">{{ __('Evidence #:id', ['id' => $evidence->getKey()]) }}</a>
                            — {{ __(ucwords(str_replace('_', ' ', $evidence->status))) }}
                        </p>
                    @empty
                        <p class="sa-text">{{ __('No termination evidence has been submitted.') }}</p>
                    @endforelse
                    <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.authority.evidence.store', $document) }}">
                        @csrf
                        <label class="sa-label" for="revocation-evidence">{{ __('Evidence file (PDF, JPG or PNG; max 10 MB)') }}</label>
                        <input class="sa-input" id="revocation-evidence" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                        <label class="sa-label" for="evidence-comments">{{ __('Evidence comments (optional; visible only to document parties and reviewers)') }}</label>
                        <textarea class="sa-input" id="evidence-comments" name="comments" maxlength="10000">{{ old('comments') }}</textarea>
                        <button class="sa-btn sa-mt-4" type="submit">{{ __('Submit termination evidence for review') }}</button>
                    </form>
                </section>
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
