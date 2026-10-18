@extends($embedded ? 'secure-access::messaging.layout' : 'secure-access::layouts.app')

@section('title', __('Team messages'))
@section('header', __('Team messages'))

@section('content')
    <section class="sa-section">
        <a class="sa-btn" href="{{ route('secure-access.messaging.create', ['embedded' => $embedded ? 1 : 0]) }}">{{ __('New conversation') }}</a>
        <p class="sa-text">{{ __('Only explicitly selected participants can see a conversation. Leaving the team removes access.') }}</p>
        @forelse ($conversations as $conversation)
            <p class="sa-text">
                <a class="sa-link" href="{{ route('secure-access.messaging.show', [$conversation, 'embedded' => $embedded ? 1 : 0]) }}">
                    {{ __('Conversation #:number', ['number' => $conversation->id]) }}:
                    {{ $conversation->participants->pluck('name')->implode(', ') }}
                    @if ($conversation->unread_count)
                        — {{ __(':count unread', ['count' => $conversation->unread_count]) }}
                    @endif
                </a>
            </p>
        @empty
            <p class="sa-text">{{ __('No conversations yet.') }}</p>
        @endforelse
        {{ $conversations->appends(['embedded' => $embedded ? 1 : 0])->links() }}
    </section>
@endsection
