@extends('secure-access::layouts.app')

@section('title', __('Your team'))
@section('header', __('Your team'))

@section('content')
    @include('secure-access::partials.errors', ['messages' => $errors->all()])

    @if (session('status'))
        <p class="sa-text">{{ session('status') }}</p>
    @endif

    @if ($inviteeRoles)
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Invite someone to your team') }}</h2>
            <p class="sa-text">{{ __('Only their email address is needed.') }}</p>
            <form method="POST" action="{{ route('secure-access.team.store') }}">
                @csrf
                <label class="sa-label" for="team-invite-email">{{ __('Email address') }}</label>
                <input class="sa-input" id="team-invite-email" type="email" name="email" value="{{ old('email') }}" required maxlength="255">
                @if ($role === 'self_advocate')
                    <label class="sa-label sa-mt-4" for="invitee_role">{{ __('Invite as') }}</label>
                    <select class="sa-input" id="invitee_role" name="invitee_role" required>
                        <option value="">{{ __('Select…') }}</option>
                        @foreach ($inviteeRoles as $inviteeRole)
                            <option value="{{ $inviteeRole }}" @selected(old('invitee_role') === $inviteeRole)>
                                {{ $inviteeRole === 'professional' ? __('Professional') : __('Family member / Caregiver') }}
                            </option>
                        @endforeach
                    </select>
                @endif
                <button class="sa-btn sa-mt-4" type="submit">{{ __('Send invitation') }}</button>
            </form>
        </section>
    @endif

    <section class="sa-section">
        <h2 class="sa-title">{{ __('Your team members') }}</h2>
        @forelse ($team as $member)
            <div class="sa-option">
                <strong>{{ $member['label'] }}: {{ $member['name'] }}</strong>
                <form method="POST" action="{{ route('secure-access.team.relationships.destroy', $member['id']) }}">
                    @csrf
                    @method('DELETE')
                    <button class="sa-btn sa-mt-4" type="submit">{{ __('Remove') }}</button>
                </form>
            </div>
        @empty
            <p class="sa-text">{{ __('You have no team members yet.') }}</p>
        @endforelse
    </section>

    @if ($incoming->isNotEmpty())
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Invitations for you') }}</h2>
            @foreach ($incoming as $invitation)
                <div class="sa-option">
                    <p class="sa-text">{{ __('A :role invited you to join their team.', ['role' => $invitation->inviter_role === 'professional' ? __('professional') : __('family member / caregiver')]) }}</p>
                    <form method="POST" action="{{ route('secure-access.team.invitations.accept', $invitation) }}">
                        @csrf
                        <button class="sa-btn" type="submit">{{ __('Accept') }}</button>
                    </form>
                    <form method="POST" action="{{ route('secure-access.team.invitations.decline', $invitation) }}">
                        @csrf
                        @method('DELETE')
                        <button class="sa-btn sa-mt-4" type="submit">{{ __('Decline') }}</button>
                    </form>
                </div>
            @endforeach
        </section>
    @endif

    @if ($outgoing->isNotEmpty())
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Pending invitations') }}</h2>
            <ul class="sa-list">
                @foreach ($outgoing as $invitation)
                    <li>{{ $invitation->invitee_email }} — {{ $invitation->invitee_role === 'self_advocate' ? __('Client / Loved one') : ($invitation->invitee_role === 'professional' ? __('Professional') : __('Family member / Caregiver')) }}</li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
