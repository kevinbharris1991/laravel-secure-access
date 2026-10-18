@extends('secure-access::layouts.app')

@section('title', __('Authority document'))
@section('header', __('Authority document'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('status'))
        <p class="sa-text" role="status">{{ session('status') }}</p>
    @endif
    <section class="sa-section">
        <h2 class="sa-title">{{ $document->type === 'power_of_attorney' ? __('Power of attorney — agent authority') : __(ucwords(str_replace('_', ' ', $document->type))) }}</h2>
        <p class="sa-text">{{ __('Document #:id', ['id' => $document->getKey()]) }} — {{ $document->revision === null ? __('Legacy revision not assigned') : __('Rev:number', ['number' => $document->revision]) }} — {{ __('Ward #:id', ['id' => $document->ward_id ?? $document->relationship->self_advocate_id]) }}</p>
        <p class="sa-text">{{ __('Review decision') }}: {{ __(ucfirst($document->review_decision)) }}</p>
        <p class="sa-text">{{ __('Lifecycle status') }}: {{ __(ucfirst($document->lifecycle_status)) }}</p>
        <p class="sa-text">{{ __('Website access status') }}: {{ $document->statusLabel() }}</p>
        <p class="sa-text">{{ __('Confirmation records a website review of submitted evidence. It does not establish legal authority, appoint a guardian, or certify legal validity. A power-of-attorney agent is not a guardian. Website capabilities require a confirmed, active document within its effective dates and an active relationship.') }}</p>
        @if ($document->lifecycle_status === 'suspended')
            <p class="sa-text">{{ __('Website representative access is suspended. This is not a determination that legal authority has been revoked or terminated.') }}</p>
        @endif
        <a class="sa-link" href="{{ route('secure-access.team.authority-documents.download', [$document->team_member_id, $document]) }}">{{ __('Download original evidence') }}</a>
        @if ($document->approved_scopes)
            <h3 class="sa-title">{{ __('Recorded website capabilities') }}</h3>
            <ul>@foreach ($document->approved_scopes as $scope)<li>{{ $scope }}</li>@endforeach</ul>
            <p class="sa-text">{{ __('Effective') }}: {{ $document->effective_at?->toDateTimeString() ?? __('Not specified') }} — {{ __('Expires') }}: {{ $document->expires_at?->toDateTimeString() ?? __('No expiry') }}</p>
            <p class="sa-text">{{ __('Recorded capabilities are not usable when the review, lifecycle, dates, or relationship do not permit access.') }}</p>
        @endif
    </section>

    @if (($canViewHistory ?? false))
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Review and lifecycle history') }}</h2>
            @forelse ($history as $review)
                <p class="sa-text">{{ __(ucwords(str_replace('_', ' ', $review['decision']))) }} — {{ $review['reviewed_at'] }}</p>
                <p class="sa-text">{{ __('Reviewer account #:id', ['id' => $review['reviewed_by'] ?? __('Not recorded')]) }}</p>
                <p class="sa-text" style="white-space: pre-wrap">{{ $review['comments'] }}</p>
                @if (! empty($review['confirmed_capabilities']) || (in_array($review['decision'], ['confirmed', 'approved'], true) && ! empty($review['approved_scopes'])))
                    <h3 class="sa-title">{{ __('Capabilities confirmed in this review') }}</h3>
                    <ul>
                        @foreach ($review['confirmed_capabilities'] ?? $review['approved_scopes'] ?? [] as $scope)
                            <li>{{ $scope }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (! empty($review['effective_at']) || ! empty($review['expires_at']))
                    <p class="sa-text">{{ __('Recorded effective date') }}: {{ $review['effective_at'] ?? __('Not specified') }} — {{ __('Recorded expiration') }}: {{ $review['expires_at'] ?? __('No expiry') }}</p>
                @endif
            @empty
                <p class="sa-text">{{ __('No reviews yet.') }}</p>
            @endforelse
        </section>
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Revision history') }}</h2>
            @if ($document->previous_document_id)
                <p><a class="sa-link" href="{{ route('secure-access.authority.show', $document->previous_document_id) }}">{{ __('Previous version') }}</a></p>
            @endif
            @forelse ($revisions ?? [] as $revision)
                <p><a class="sa-link" href="{{ route('secure-access.authority.show', $revision) }}">{{ __('Rev:number', ['number' => $revision->revision ?? '?']) }} — {{ __('Document #:id', ['id' => $revision->getKey()]) }}</a> — {{ $revision->created_at }}</p>
            @empty
                <p class="sa-text">{{ __('No additional revisions.') }}</p>
            @endforelse
        </section>
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Audit history') }}</h2>
            @forelse ($audits ?? [] as $audit)
                <p class="sa-text">{{ __(ucwords(str_replace('_', ' ', $audit->action))) }} — {{ __('Actor #:id', ['id' => $audit->actor_id]) }} — {{ $audit->created_at }}</p>
            @empty
                <p class="sa-text">{{ __('No audit events.') }}</p>
            @endforelse
        </section>
    @endif

    @if ($canReview && in_array($document->lifecycle_status, ['active', 'inactive'], true) && $document->review_decision !== 'denied')
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Record review decision') }}</h2>
            <form method="POST" action="{{ route('secure-access.authority.update', $document) }}">
                @csrf
                <label class="sa-label" for="review-decision">{{ __('Review decision') }}</label>
                <select class="sa-input" id="review-decision" name="decision" required>
                    @foreach (['confirmed' => __('Confirm'), 'denied' => __('Deny'), 'inquired' => __('Inquire — request more information')] as $decision => $label)
                        @if ($decision !== 'confirmed' || $document->review_decision !== 'confirmed')
                            <option value="{{ $decision }}" @selected(old('decision') === $decision)>{{ $label }}</option>
                        @endif
                    @endforeach
                </select>
                <p class="sa-text">{{ __('Confirmation does not establish legal authority. Select only website capabilities supported by the evidence, with an effective date and optional expiration. Capability and date fields apply only when confirming. Review decisions never change lifecycle status: confirmation cannot reactivate an inactive document. An unresolved inquiry prevents use of its capabilities. Lifecycle changes are recorded separately below.') }}</p>
                <p class="sa-text">{{ __('For update, payment, cancellation, submission, or commitment actions, also select the same module’s read capability. No capabilities are added automatically. A replacement for currently active authority must take effect immediately.') }}</p>
                @foreach ($capabilities as $domain => $scopes)
                    <fieldset class="sa-section">
                        <legend>{{ __(ucwords(str_replace('_', ' ', $domain))) }}</legend>
                        @foreach ($scopes as $scope)
                            <label class="sa-label"><input type="checkbox" name="approved_scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('approved_scopes', []), true))> {{ $scope }}</label>
                        @endforeach
                    </fieldset>
                @endforeach
                <label class="sa-label" for="effective-at">{{ __('Effective date (required for confirmation)') }}</label>
                <input class="sa-input" type="date" id="effective-at" name="effective_at" value="{{ old('effective_at') }}">
                <label class="sa-label" for="expires-at">{{ __('Expiration date (optional)') }}</label>
                <input class="sa-input" type="date" id="expires-at" name="expires_at" value="{{ old('expires_at') }}">
                <label class="sa-label" for="review-reason">{{ __('Review reason (required; restricted to independent reviewers)') }}</label>
                <textarea class="sa-input" id="review-reason" name="comments" required maxlength="10000">{{ old('comments') }}</textarea>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Record review decision') }}</button>
            </form>
        </section>
    @endif

    @if ($canReview && in_array($document->lifecycle_status, ['active', 'inactive'], true))
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Change document lifecycle') }}</h2>
            <p class="sa-text">{{ __('Lifecycle is separate from the review decision. Marking a document active does not confirm it, grant capabilities, or restore an ended relationship. Inactive and superseded documents cannot authorize access. These website actions do not revoke legal authority.') }}</p>
            <form method="POST" action="{{ route('secure-access.authority.lifecycle', $document) }}">
                @csrf
                <label class="sa-label" for="lifecycle-status">{{ __('Lifecycle status') }}</label>
                <select class="sa-input" id="lifecycle-status" name="lifecycle_status" required>
                    @foreach (['active', 'inactive', 'superseded'] as $lifecycle)
                        <option value="{{ $lifecycle }}" @selected(old('lifecycle_status', $document->lifecycle_status) === $lifecycle)>{{ __(ucfirst($lifecycle)) }}</option>
                    @endforeach
                </select>
                <label class="sa-label" for="lifecycle-reason">{{ __('Lifecycle reason (required; restricted to independent reviewers)') }}</label>
                <textarea class="sa-input" id="lifecycle-reason" name="comments" required maxlength="10000">{{ old('comments') }}</textarea>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Record lifecycle change') }}</button>
            </form>
        </section>
    @endif

    <section class="sa-section">
        <h2 class="sa-title">{{ __('Evidence of legal revocation or termination') }}</h2>
        <p class="sa-text">{{ __('This website cannot revoke guardianship, conservatorship, or power of attorney. Parties may submit evidence that legal authority has ended. Submission alone does not change access. An independent Administrative Director must verify it before recording termination. The represented adult’s ordinary permissions are never removed.') }}</p>
        @forelse ($document->revocationEvidence->sortByDesc('id') as $evidence)
            <p class="sa-text"><a class="sa-link" href="{{ route('secure-access.authority.evidence.show', $evidence) }}">{{ __('Evidence #:id', ['id' => $evidence->getKey()]) }}</a> — {{ __(ucwords(str_replace('_', ' ', $evidence->status))) }}</p>
        @empty
            <p class="sa-text">{{ __('No termination evidence has been submitted.') }}</p>
        @endforelse
        <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.authority.evidence.store', $document) }}">
            @csrf
            <label class="sa-label" for="revocation-evidence">{{ __('Evidence file (PDF, JPG or PNG; maximum 10 MiB)') }}</label>
            <input class="sa-input" id="revocation-evidence" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
            <label class="sa-label" for="evidence-comments">{{ __('Evidence comments (optional)') }}</label>
            <textarea class="sa-input" id="evidence-comments" name="comments" maxlength="10000">{{ old('comments') }}</textarea>
            <button class="sa-btn sa-mt-4" type="submit">{{ __('Submit termination evidence for review') }}</button>
        </form>
    </section>

    @if ($canShare)
        @include('secure-access::authority.shares.manage')
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Prepare departmental disclosure draft') }}</h2>
            <p class="sa-text">{{ __('Save only the minimum-necessary healthcare or financial summary and limitations. A draft is private and is not delivered until explicitly published. Upload a separately redacted PDF; the original is never modified or shared.') }}</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.authority.shares.store', $document) }}">
                @csrf
                <label class="sa-label" for="share-department">{{ __('Department') }}</label>
                <select class="sa-input" id="share-department" name="department" required>
                    <option value="medical-nursing" @selected(old('department') === 'medical-nursing')>{{ __('Medical/Nursing — healthcare authority') }}</option>
                    <option value="finance-accounting" @selected(old('department') === 'finance-accounting')>{{ __('Finance — financial authority') }}</option>
                </select>
                <label class="sa-label" for="share-summary">{{ __('Healthcare or financial authority summary') }}</label>
                <textarea class="sa-input" id="share-summary" name="summary" maxlength="5000" required>{{ old('summary') }}</textarea>
                <label class="sa-label" for="share-limitations">{{ __('Relevant healthcare or billing/payment limitations') }}</label>
                <textarea class="sa-input" id="share-limitations" name="limitations" maxlength="5000" required>{{ old('limitations') }}</textarea>
                <label class="sa-label" for="share-effective">{{ __('Disclosure effective date (optional; blank means immediately)') }}</label>
                <input class="sa-input" id="share-effective" type="date" name="effective_at" value="{{ old('effective_at', $document->effective_at?->toDateString()) }}">
                <label class="sa-label" for="share-expires">{{ __('Disclosure expiration date (optional)') }}</label>
                <input class="sa-input" id="share-expires" type="date" name="expires_at" value="{{ old('expires_at', $document->expires_at?->toDateString()) }}">
                <p class="sa-text">{{ __('Disclosure dates include the selected day in the application timezone. Authority dates and current assignment still limit access independently.') }}</p>
                <label class="sa-label" for="share-copy">{{ __('Redacted PDF (maximum 10 MiB; PDF extension and MIME type required)') }}</label>
                <input class="sa-input" id="share-copy" type="file" name="document" accept=".pdf,application/pdf" required>
                <label class="sa-label"><input type="checkbox" name="redacted_confirmation" value="1" required> {{ __('I confirm this PDF is redacted for the selected department.') }}</label>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Save disclosure draft') }}</button>
            </form>
        </section>
    @endif
    @if ($canResubmit)
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Submit a new version') }}</h2>
            <p class="sa-text">{{ __('Original evidence and restricted review history are retained. A new upload does not automatically replace currently effective authority.') }}</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.team.authority-documents.store', $document->team_member_id) }}">
                @csrf
                <input type="hidden" name="previous_document_id" value="{{ $document->getKey() }}">
                <input type="hidden" name="type" value="{{ $document->type }}">
                <label class="sa-label" for="replacement">{{ __('New original evidence (PDF, JPG or PNG; maximum 10 MiB)') }}</label>
                <input class="sa-input" id="replacement" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Submit new version') }}</button>
            </form>
        </section>
    @endif
@endsection
