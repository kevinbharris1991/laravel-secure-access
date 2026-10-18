@extends('secure-access::layouts.app')

@section('title', __('Shared authority information'))
@section('header', __('Shared authority information'))

@section('content')
    <section class="sa-section">
        <h2 class="sa-title">{{ __(ucwords(str_replace('_', ' ', $share->document->type))) }}</h2>
        <h3 class="sa-title">{{ $share->department === 'finance-accounting' ? __('Financial authority summary') : __('Healthcare authority summary') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ $share->summary }}</p>
        <h3 class="sa-title">{{ $share->department === 'finance-accounting' ? __('Billing/payment limitations') : __('Relevant healthcare limitations') }}</h3>
        <p class="sa-text" style="white-space: pre-wrap">{{ $share->limitations }}</p>
        <p class="sa-text">{{ __('Effective') }}: {{ $share->document->effective_at?->toDateTimeString() ?? __('Immediately') }}</p>
        <p class="sa-text">{{ __('Expires') }}: {{ $share->document->expires_at?->toDateTimeString() ?? __('No expiry') }}</p>
        <a class="sa-link" href="{{ route('secure-access.authority.shares.download', $share) }}">{{ __('Download redacted copy') }}</a>
    </section>
@endsection
