@extends('secure-access::layouts.app')

@section('title', __('Financial application permission decisions'))
@section('header', __('Financial application permission decisions'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    @if (session('status'))
        <p class="sa-text" role="status">{{ session('status') }}</p>
    @endif
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Published financial disclosure') }}</h2>
        <p class="sa-text">{{ __('This page contains only the assigned financial disclosure and authorized application decisions. It does not reveal original authority evidence or healthcare information.') }}</p>
        <h3 class="sa-title">{{ __('Financial authority summary') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ data_get($share, 'summary') }}</p>
        <h3 class="sa-title">{{ __('Billing/payment limitations') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ data_get($share, 'limitations') }}</p>
        <p class="sa-text">{{ __('These actions revoke or reinstate guest permission to apply for financial aid or tuition, subject to the current published financial disclosure and explicit confirmed capabilities. They do not revoke legal authority, change document review or lifecycle, end a website relationship, or grant unrelated account permissions.') }}</p>
        <p class="sa-text">{{ __('This website currently uses one grant applications module for both purposes. A current financial aid or tuition restriction therefore conservatively blocks creation, editing, and submission in that module. Existing records remain separate from this permission decision.') }}</p>
    </section>
    <section class="sa-section">
        <h2 class="sa-title">{{ __('Authorized application actions') }}</h2>
        @forelse ($availableCapabilities as $capability)
            @php([$target, $action] = explode('.', $capability, 2))
            <section class="sa-section">
                <h3 class="sa-title">{{ $target === 'financial_aid' ? __('Financial aid application permission') : __('Tuition application permission') }} — {{ $action === 'revoke' ? __('Revoke') : __('Reinstate') }}</h3>
                <form method="POST" action="{{ route('secure-access.finance-actions.store', data_get($share, 'id')) }}">
                    @csrf
                    <input type="hidden" name="target" value="{{ $target }}">
                    <input type="hidden" name="action" value="{{ $action }}">
                    <input type="hidden" name="share_revision" value="{{ data_get($share, 'revision', $shareRevision ?? '') }}">
                    <label class="sa-label" for="basis-{{ $loop->index }}">{{ __('Decision basis (required)') }}</label>
                    <textarea class="sa-input" id="basis-{{ $loop->index }}" name="basis" maxlength="5000" required>{{ old('target') === $target && old('action') === $action ? old('basis') : '' }}</textarea>
                    <label class="sa-label" for="reason-{{ $loop->index }}">{{ __('Decision reason (required)') }}</label>
                    <textarea class="sa-input" id="reason-{{ $loop->index }}" name="reason" maxlength="5000" required>{{ old('target') === $target && old('action') === $action ? old('reason') : '' }}</textarea>
                    <label class="sa-label" for="effective-{{ $loop->index }}">{{ __('Effective date (required)') }}</label>
                    <input class="sa-input" id="effective-{{ $loop->index }}" type="date" name="effective_at" value="{{ old('target') === $target && old('action') === $action ? old('effective_at') : '' }}" required>
                    <p class="sa-text">{{ __('Future decisions remain pending until the effective date and a fresh eligibility check. Source or assignment changes invalidate source-bound overrides; reinstatement does not grant any unrelated ACL permission.') }}</p>
                    <button class="sa-btn sa-mt-4" type="submit">{{ $action === 'revoke' ? __('Revoke application permission') : __('Reinstate application permission') }}</button>
                </form>
            </section>
        @empty
            <p class="sa-text">{{ __('No application actions are currently authorized for your assignment and disclosure.') }}</p>
        @endforelse
    </section>
    @include('secure-access::authority.financial-decision-history', ['decisions' => $decisions])
@endsection
