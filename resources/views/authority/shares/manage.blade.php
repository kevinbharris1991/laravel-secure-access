<section class="sa-section">
    <h2 class="sa-title">{{ __('Departmental disclosure drafts and publications') }}</h2>
    <p class="sa-text">{{ __('Drafts are visible only to authorized independent Administrative Directors. Publication requires confirmed, active authority and a live relationship; future effective dates are allowed but withhold recipient access and delivery until effective. Only the currently assigned staff member can receive the disclosure. Publishing does not expose original evidence. Review every summary, limitation, date, and PDF redaction before publication.') }}</p>
    @forelse ($departmentShares ?? [] as $departmentShare)
        <section class="sa-section">
            <h3 class="sa-title">{{ $departmentShare->department === 'finance-accounting' ? __('Finance — financial authority') : __('Medical/Nursing — healthcare authority') }} — {{ __(ucfirst($departmentShare->status)) }} — {{ __('Revision :number', ['number' => $departmentShare->revision]) }}</h3>
            @if ($departmentShare->status === 'removed')
                <p class="sa-text">{{ __('This disclosure has been removed. It is retained for restricted history and cannot be accessed by departmental recipients.') }}</p>
            @else
                <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.authority.shares.update', $departmentShare) }}">
                    @csrf
                    @method('PUT')
                    <label class="sa-label" for="summary-{{ $departmentShare->id }}">{{ $departmentShare->department === 'finance-accounting' ? __('Financial authority summary') : __('Healthcare authority summary') }}</label>
                    <textarea class="sa-input" id="summary-{{ $departmentShare->id }}" name="summary" maxlength="5000" required>{{ $departmentShare->summary }}</textarea>
                    <label class="sa-label" for="limitations-{{ $departmentShare->id }}">{{ $departmentShare->department === 'finance-accounting' ? __('Billing/payment limitations') : __('Relevant healthcare limitations') }}</label>
                    <textarea class="sa-input" id="limitations-{{ $departmentShare->id }}" name="limitations" maxlength="5000" required>{{ $departmentShare->limitations }}</textarea>
                    <label class="sa-label" for="effective-{{ $departmentShare->id }}">{{ __('Disclosure effective date (optional; blank means immediately)') }}</label>
                    <input class="sa-input" id="effective-{{ $departmentShare->id }}" type="date" name="effective_at" value="{{ $departmentShare->effective_at?->toDateString() }}">
                    <label class="sa-label" for="expires-{{ $departmentShare->id }}">{{ __('Disclosure expiration date (optional)') }}</label>
                    <input class="sa-input" id="expires-{{ $departmentShare->id }}" type="date" name="expires_at" value="{{ $departmentShare->expires_at?->toDateString() }}">
                    <label class="sa-label" for="redacted-{{ $departmentShare->id }}">{{ __('Replacement redacted PDF (optional; maximum 10 MiB)') }}</label>
                    <input class="sa-input" id="redacted-{{ $departmentShare->id }}" type="file" name="document" accept=".pdf,application/pdf">
                    <p class="sa-text">{{ __('Legacy copies must be replaced with a verified redacted PDF before publication. Leave the file field empty to retain a previously verified PDF.') }}</p>
                    <label class="sa-label"><input type="checkbox" name="redacted_confirmation" value="1"> {{ __('I confirm any replacement PDF is redacted for this department.') }}</label>
                    <label class="sa-label" for="publication-{{ $departmentShare->id }}">{{ __('Publication state') }}</label>
                    <select class="sa-input" id="publication-{{ $departmentShare->id }}" name="status" required>
                        <option value="draft" @selected($departmentShare->status === 'draft')>{{ __('Private draft — no recipient access') }}</option>
                        <option value="published" @selected($departmentShare->status === 'published')>{{ __('Publish — deliver when effective and eligible') }}</option>
                    </select>
                    <button class="sa-btn sa-mt-4" type="submit">{{ __('Save disclosure and publication state') }}</button>
                </form>
                <form method="POST" action="{{ route('secure-access.authority.shares.destroy', $departmentShare) }}">
                    @csrf
                    @method('DELETE')
                    <p class="sa-text">{{ __('Removal immediately ends recipient access; retained originals and restricted history are unchanged.') }}</p>
                    <button class="sa-btn" type="submit">{{ __('Remove departmental disclosure') }}</button>
                </form>
            @endif
        </section>
    @empty
        <p class="sa-text">{{ __('No departmental disclosures have been prepared.') }}</p>
    @endforelse
</section>
