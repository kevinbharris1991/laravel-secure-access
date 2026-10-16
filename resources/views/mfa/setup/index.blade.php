@extends($enrolling ? 'secure-access::layouts.guest' : 'secure-access::layouts.app')

@section('title', __('Set up multi-factor authentication'))
@section('header', __('Add a multi-factor authentication method'))

@section('content')
    <div @class(['sa-section' => ! $enrolling])>
        <div>
            @if ($enrolling)
                <h1 class="sa-title">{{ __('Secure your account') }}</h1>
                <p class="sa-text">
                    {{ __('Multi-factor authentication is required. Register at least one method; you will use one of your registered methods every time you log in. You can add more methods later.') }}
                </p>
            @else
                <p class="sa-text" style="margin-top:0">{{ __('Choose the type of method you want to add to your account.') }}</p>
            @endif

            @foreach ($types as $type)
                <a class="sa-option" href="{{ route('secure-access.setup.create', $type) }}">
                    <strong>{{ \SecureAccess\Models\MfaMethod::typeLabel($type) }}</strong>
                    <span class="sa-text">{{ \SecureAccess\Models\MfaMethod::typeDescription($type) }}</span>
                </a>
            @endforeach

            <div class="sa-actions">
                @if ($enrolling)
                    @include('secure-access::partials.cancel')
                @else
                    <a class="sa-link" href="{{ route('secure-access.security') }}">{{ __('Back') }}</a>
                @endif
            </div>
        </div>
    </div>
@endsection
