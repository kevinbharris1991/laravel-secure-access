<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        @include('secure-access::partials.styles')
    </head>
    <body>
        <div class="sa-guest">
            <div>
                <a href="/" aria-label="{{ config('app.name', 'Laravel') }}">
                    @include('secure-access::partials.logo')
                </a>
            </div>

            <div class="sa-card">
                @if (session('status'))
                    <div class="sa-status">{{ session('status') }}</div>
                @endif

                @yield('content')
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
