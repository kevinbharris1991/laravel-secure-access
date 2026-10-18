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
                    <button class="sa-btn sa-mt-4" type="submit">{{ __('End website relationship') }}</button>
                </form>
                <p class="sa-text">{{ __('Ending this website relationship removes representative website access. It does not revoke guardianship, conservatorship, or power of attorney. Submit evidence of legal termination for independent review.') }}</p>
                <h3 class="sa-title sa-mt-4">{{ __('Legal authority documents') }}</h3>
                @forelse ($member['authority_documents'] as $document)
                    <p class="sa-text">
                        {{ __(ucwords(str_replace('_', ' ', $document['type']))) }}:
                        <a class="sa-link" href="{{ route('secure-access.authority.show', $document['id']) }}">{{ __('View document and review history') }}</a>
                        — {{ $document['status_label'] }}
                    </p>
                @empty
                    <p class="sa-text">{{ __('No authority documents have been uploaded.') }}</p>
                @endforelse
                @if ($member['can_attach_authority'])
                    <form method="POST" enctype="multipart/form-data" action="{{ route('secure-access.team.authority-documents.store', $member['id']) }}">
                        @csrf
                        <label class="sa-label sa-mt-4" for="authority-type-{{ $member['id'] }}">{{ __('Authority type') }}</label>
                        <select class="sa-input" id="authority-type-{{ $member['id'] }}" name="type" required>
                            <option value="">{{ __('Select…') }}</option>
                            @foreach ($authorityDocumentTypes as $type)
                                <option value="{{ $type }}" @selected(old('type') === $type)>{{ __(ucwords(str_replace('_', ' ', $type))) }}</option>
                            @endforeach
                        </select>
                        <label class="sa-label sa-mt-4" for="authority-document-{{ $member['id'] }}">{{ __('Upload document (PDF, JPG, or PNG; max 10 MB)') }}</label>
                        <input class="sa-input" id="authority-document-{{ $member['id'] }}" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                        <button class="sa-btn sa-mt-4" type="submit">{{ __('Attach authority document') }}</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="sa-text">{{ __('You have no team members yet.') }}</p>
        @endforelse
    </section>

    @if ($endedAuthorityDocuments->isNotEmpty())
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Authority history for ended website relationships') }}</h2>
            <p class="sa-text">{{ __('Evidence remains available to the document parties. You can still submit evidence of legal termination for independent review.') }}</p>
            @foreach ($endedAuthorityDocuments as $document)
                <p class="sa-text">
                    <a class="sa-link" href="{{ route('secure-access.authority.show', $document) }}">{{ __('Document #:id', ['id' => $document->getKey()]) }}</a>
                    — {{ $document->statusLabel() }}
                </p>
            @endforeach
            {{ $endedAuthorityDocuments->links() }}
        </section>
    @endif

    @if ($incoming->isNotEmpty())
        <section class="sa-section">
            <h2 class="sa-title">{{ __('Invitations for you') }}</h2>
            @foreach ($incoming as $invitation)
                <div class="sa-option">
                    <p class="sa-text">{{ __(':name invited you to join their team.', ['name' => $invitation['inviter_name']]) }}</p>
                    <form method="POST" action="{{ route('secure-access.team.invitations.accept', $invitation['id']) }}">
                        @csrf
                        <button class="sa-btn" type="submit">{{ __('Accept') }}</button>
                    </form>
                    <form method="POST" action="{{ route('secure-access.team.invitations.decline', $invitation['id']) }}">
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
