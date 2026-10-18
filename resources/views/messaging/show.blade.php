@extends($embedded ? 'secure-access::messaging.layout' : 'secure-access::layouts.app')

@section('title', __('Conversation'))
@section('header', __('Conversation'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    <p><a class="sa-link" href="{{ route('secure-access.messaging.index', ['embedded' => $embedded ? 1 : 0]) }}">{{ __('All conversations') }}</a></p>
    <section class="sa-section" aria-label="{{ __('Messages') }}">
        <h2 class="sa-title">{{ __('Conversation #:number', ['number' => $conversation->id]) }}</h2>
        @forelse ($messages->reverse() as $message)
            <article class="sa-option">
                <h3 class="sa-label">{{ $message->sender?->name ?: __('Former team member') }}</h3>
                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('Y-m-d H:i') }}</time>
                <p class="sa-text" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $message->body }}</p>
                @if ((string) $message->sender_id === $actor)
                    <p class="sa-text">{{ __('Read by :count participants', ['count' => $message->reads->count()]) }}</p>
                @endif
            </article>
        @empty
            <p class="sa-text">{{ __('No messages yet.') }}</p>
        @endforelse
        {{ $messages->appends(['embedded' => $embedded ? 1 : 0])->links() }}
        @if ($messages->isNotEmpty())
            <form method="POST" action="{{ route('secure-access.messaging.read', $conversation) }}">
                @csrf
                <input type="hidden" name="embedded" value="{{ $embedded ? 1 : 0 }}">
                @foreach ($messages as $message)
                    <input type="hidden" name="message_ids[]" value="{{ $message->id }}">
                @endforeach
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Mark these displayed messages as read') }}</button>
            </form>
        @endif
    </section>
    <section class="sa-section">
        <form method="POST" action="{{ route('secure-access.messaging.send', $conversation) }}">
            @csrf
            <input type="hidden" name="embedded" value="{{ $embedded ? 1 : 0 }}">
            <label class="sa-label" for="message-body">{{ __('Message') }}</label>
            <textarea class="sa-input" id="message-body" name="body" rows="4" maxlength="10000" required autocomplete="off"></textarea>
            <button class="sa-btn sa-mt-4" type="submit">{{ __('Send message') }}</button>
        </form>
    </section>
@endsection
