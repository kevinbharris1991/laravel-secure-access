@extends('secure-access::layouts.app')

@section('title', __('Shared authority information'))
@section('header', __('Shared authority information'))

@section('content')
    <section class="sa-section">
        <p class="sa-text">{{ __('This is a minimum-necessary departmental disclosure, not the original authority document or an independent determination of legal authority.') }}</p>
        <h2 class="sa-title">{{ __(ucwords(str_replace('_', ' ', $share->document->type))) }}</h2>
        <h3 class="sa-title">{{ $share->department === 'finance-accounting' ? __('Financial authority summary') : __('Healthcare authority summary') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ $share->summary }}</p>
        <h3 class="sa-title">{{ $share->department === 'finance-accounting' ? __('Billing/payment limitations') : __('Relevant healthcare limitations') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ $share->limitations }}</p>
        <p class="sa-text">{{ __('Disclosure effective date') }}: {{ $share->effective_at?->toDateString() ?? __('Immediately') }}</p>
        <p class="sa-text">{{ __('Disclosure expiration date') }}: {{ $share->expires_at?->toDateString() ?? __('No expiry') }}</p>
        <p class="sa-text">{{ __('Authority effective') }}: {{ $share->document->effective_at?->toDateTimeString() ?? __('Not specified') }} — {{ __('Authority expires') }}: {{ $share->document->expires_at?->toDateTimeString() ?? __('No expiry') }}</p>
        <a class="sa-link" href="{{ route('secure-access.authority.shares.download', $share) }}">{{ __('Download published redacted PDF') }}</a>
        @if ($share->department === 'finance-accounting')
            <p><a class="sa-link" href="{{ route('secure-access.finance-actions.show', $share) }}">{{ __('Financial aid and tuition application permissions') }}</a></p>
        @endif
    </section>
@endsection
