@extends('secure-access::layouts.app')

@section('title', __('Shared authority information'))
@section('header', __('Shared authority information'))

@section('content')
    <p class="sa-text">{{ __('Only authority materials specifically shared with your department are available here. Access ends immediately when authority is inactive, expired, revoked, suspended, or the website relationship ends.') }}</p>
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
