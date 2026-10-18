<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Team messages') }}</title>
    @include('secure-access::partials.styles')
</head>
<body data-messaging-unread="{{ $messagingUnread }}">
    <main class="sa-container" style="padding:1rem">
        <h1 class="sa-title">{{ __('Team messages') }}</h1>
        @yield('content')
    </main>
</body>
</html>
