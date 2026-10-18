@extends($embedded ? 'secure-access::messaging.layout' : 'secure-access::layouts.app')

@section('title', __('New conversation'))
@section('header', __('New conversation'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])
    <p><a class="sa-link" href="{{ route('secure-access.messaging.index', ['embedded' => $embedded ? 1 : 0]) }}">{{ __('All conversations') }}</a></p>
    <p class="sa-text">{{ __('Choose one team, then explicitly select yourself and everyone you want in the conversation. You do not have to include the adult whose team this is.') }}</p>
    @forelse ($teams as $team)
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Team of :name', ['name' => $team['name']]) }}</h2>
            <form method="POST" action="{{ route('secure-access.messaging.store') }}">
                @csrf
                <input type="hidden" name="embedded" value="{{ $embedded ? 1 : 0 }}">
                <input type="hidden" name="self_advocate_id" value="{{ $team['id'] }}">
                <fieldset>
                    <legend class="sa-label">{{ __('Conversation participants (select at least two)') }}</legend>
                    @foreach ($team['members'] as $member)
                        <p>
                            <label>
                                <input type="checkbox" name="participants[]" value="{{ $member->getKey() }}">
                                {{ $member->name }} {{ (string) $member->getKey() === $actor ? __('(you — required)') : '' }}
                            </label>
                        </p>
                    @endforeach
                </fieldset>
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Create conversation') }}</button>
            </form>
        </section>
    @empty
        <p class="sa-text">{{ __('Join a team before starting a conversation.') }}</p>
    @endforelse
@endsection
