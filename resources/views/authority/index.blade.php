@extends('secure-access::layouts.app')

@section('title', __('Authority document review'))
@section('header', __('Authority document review'))

@section('content')
    <p class="sa-text">{{ __('Review submitted evidence before granting specific, time-limited capabilities. Uploading evidence does not grant access.') }}</p>
    <form method="GET" action="{{ route('secure-access.authority.index') }}">
        <label class="sa-label" for="authority-status">{{ __('Status') }}</label>
        <select class="sa-input" id="authority-status" name="status">
            @foreach (array_merge(['all'], \SecureAccess\Models\TeamAuthorityDocument::STATUSES) as $filter)
                <option value="{{ $filter }}" @selected($status === $filter)>{{ __(ucwords(str_replace('_', ' ', $filter))) }}</option>
            @endforeach
        </select>
        <button class="sa-btn" type="submit">{{ __('Filter') }}</button>
    </form>
    <section class="sa-section">
        @forelse ($documents as $document)
            <p class="sa-text">
                <a class="sa-link" href="{{ route('secure-access.authority.show', $document) }}">{{ __('Document #:id', ['id' => $document->getKey()]) }}</a>
                — {{ __(ucwords(str_replace('_', ' ', $document->type))) }}
                — {{ __(ucwords(str_replace('_', ' ', $document->status))) }}
            </p>
        @empty
            <p class="sa-text">{{ __('No documents match this status.') }}</p>
        @endforelse
        {{ $documents->links() }}
    </section>
@endsection
