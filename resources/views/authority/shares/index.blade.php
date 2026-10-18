@extends('secure-access::layouts.app')

@section('title', __('Shared authority information'))
@section('header', __('Shared authority information'))

@section('content')
    <p class="sa-text">{{ __('Only published, currently effective disclosures for your current individual assignment are available here. Department membership alone does not grant access. Drafts, original evidence, review comments, and other departments’ disclosures are not available.') }}</p>
    <p class="sa-text">{{ __('Access ends when your assignment changes, authority or disclosure expires, authority becomes inactive, suspended, revoked or superseded, or the website relationship ends.') }}</p>
    <section class="sa-section">
        @forelse ($items as $share)
            <p class="sa-text">
                <a class="sa-link" href="{{ route('secure-access.authority.shares.show', $share) }}">
                    {{ __('Shared authority item #:id', ['id' => $share->getKey()]) }}
                </a>
                — {{ __(ucwords(str_replace('_', ' ', $share->document->type))) }}
            </p>
        @empty
            <p class="sa-text">{{ __('No authority materials have been shared with this department.') }}</p>
        @endforelse
        {{ $items->links() }}
    </section>
@endsection
