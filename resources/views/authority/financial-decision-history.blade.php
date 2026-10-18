<section class="sa-section">
    <h2 class="sa-title">{{ __('Financial application permission decision history') }}</h2>
    <p class="sa-text">{{ __('A recorded decision is not necessarily a current override. Future decisions remain pending until effective and eligible; source document, disclosure, capability, or assignment changes can invalidate them. Reinstatement never grants unrelated account permissions.') }}</p>
    @forelse ($decisions as $decision)
        <section class="sa-section">
            <h3 class="sa-title">{{ data_get($decision, 'target') === 'financial_aid' ? __('Financial aid application permission') : __('Tuition application permission') }} — {{ data_get($decision, 'action') === 'revoke' ? __('Revocation recorded') : __('Reinstatement recorded') }}</h3>
            <p class="sa-text">{{ __('Reviewer') }}: {{ data_get($decision, 'reviewer_name', data_get($decision, 'reviewer_id', __('Not recorded'))) }} — {{ __('Recorded at') }}: {{ data_get($decision, 'reviewed_at') }} — {{ __('Effective date') }}: {{ data_get($decision, 'effective_at') }}</p>
            @if (data_get($decision, 'decision_status_label'))
                <p class="sa-text">{{ __('Current applicability') }}: {{ data_get($decision, 'decision_status_label') }}</p>
            @endif
            <h4 class="sa-title">{{ __('Decision basis') }}</h4>
            <p class="sa-text" style="white-space: pre-wrap">{{ data_get($decision, 'basis') }}</p>
            <h4 class="sa-title">{{ __('Decision reason') }}</h4>
            <p class="sa-text" style="white-space: pre-wrap">{{ data_get($decision, 'reason') }}</p>
        </section>
    @empty
        <p class="sa-text">{{ __('No financial application permission decisions have been recorded.') }}</p>
    @endforelse
</section>
