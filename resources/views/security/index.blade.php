@extends('secure-access::layouts.app')

@section('title', __('Security'))
@section('header', __('Security'))

@section('content')
    <div class="sa-section">
        <div>
            <h2 class="sa-title">{{ __('Multi-factor authentication') }}</h2>
            <p class="sa-text">{{ __('You will be asked to use one of these methods every time you log in.') }}</p>

            <ul class="sa-list">
                @foreach ($methods as $method)
                    <li>
                        <div>
                            <strong>{{ $method->name }}</strong>
                            <span class="sa-badge">{{ $method->label() }}</span>
                            <div class="sa-text">
                                @if ($method->maskedPhone()) {{ $method->maskedPhone() }} · @endif
                                {{ $method->last_used_at ? __('Last used :time', ['time' => $method->last_used_at->diffForHumans()]) : __('Never used') }}
                            </div>
                        </div>

                        @if ($methods->count() > 1)
                            <form method="POST" action="{{ route('secure-access.methods.destroy', $method) }}" style="display:flex;gap:.5rem;align-items:center">
                                @csrf
                                @method('DELETE')
                                <input class="sa-input" style="margin:0;width:10rem" type="password" name="password" placeholder="{{ __('Password') }}" aria-label="{{ __('Password') }}" required autocomplete="current-password">
                                <button type="submit" class="sa-btn sa-btn-danger">{{ __('Remove') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @include('secure-access::partials.errors', ['messages' => $errors->removeMethod->get('password')])

            <div class="sa-mt-4">
                <a class="sa-btn" href="{{ route('secure-access.setup') }}">{{ __('Add method') }}</a>
            </div>
        </div>
    </div>

    <div class="sa-section">
        <div>
            <h2 class="sa-title">{{ __('Sign-in identifiers') }}</h2>
            <p class="sa-text">{{ __('You can log in with your username or any of your verified email addresses.') }}</p>

            <ul class="sa-list">
                @if ($username)
                    <li><div>{{ $username }} <span class="sa-badge">{{ __('Username') }}</span></div></li>
                @endif
                @if ($primaryEmail)
                    <li><div>{{ $primaryEmail }} <span class="sa-badge">{{ __('Primary') }}</span></div></li>
                @endif
                @foreach ($emails as $email)
                    <li>
                        <div>
                            {{ $email->email }}
                            @unless ($email->isVerified())
                                <span class="sa-badge sa-badge-warning">{{ __('Unverified') }}</span>
                            @endunless
                        </div>

                        <div style="display:flex;gap:1rem;align-items:center">
                            @unless ($email->isVerified())
                                <form method="POST" action="{{ route('secure-access.emails.resend', $email) }}">
                                    @csrf
                                    <button type="submit" class="sa-link">{{ __('Resend verification') }}</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('secure-access.emails.destroy', $email) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="sa-link">{{ __('Remove') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('secure-access.emails.store') }}" class="sa-mt-6">
                @csrf
                <label class="sa-label" for="email">{{ __('Add email address') }}</label>
                <input id="email" class="sa-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                @include('secure-access::partials.errors', ['messages' => $errors->addEmail->get('email')])

                <div class="sa-actions" style="justify-content:flex-start">
                    <button type="submit" class="sa-btn">{{ __('Add email') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
