@extends('secure-access::layouts.guest')

@section('title', __('Verify your identity'))

@section('content')
    <p class="sa-text sa-mb-4" style="margin-top:0">
        {{ __('For your security, confirm it is you using one of the methods registered on your account.') }}
    </p>

    @foreach ($methods as $method)
        <a class="sa-option" href="{{ route('secure-access.challenge.show', $method) }}">
            <strong>{{ $method->name }}</strong>
            <span class="sa-text">
                {{ $method->label() }}@if ($method->maskedPhone()) · {{ $method->maskedPhone() }}@endif
            </span>
        </a>
    @endforeach

    <div class="sa-actions">
        @include('secure-access::partials.cancel')
    </div>
@endsection
