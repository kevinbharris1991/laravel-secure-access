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
        <nav class="sa-nav">
            <div class="sa-nav-inner">
                <a href="{{ url(config('secure-access.home', '/')) }}" aria-label="{{ config('app.name', 'Laravel') }}">
                    @include('secure-access::partials.logo')
                </a>

                <a class="sa-link" href="{{ route('secure-access.dashboard') }}">{{ __('Dashboard') }}</a>
                <form method="POST" action="{{ route('secure-access.logout') }}">
                    @csrf
                    <button type="submit" class="sa-link">{{ __('Log Out') }}</button>
                </form>
            </div>
        </nav>

        <header class="sa-header">
            <div class="sa-container">
                <h2>@yield('header')</h2>
            </div>
        </header>

        <main class="sa-main">
            <div class="sa-container">
                @if (session('status'))
                    <div class="sa-section"><div class="sa-status" style="margin-bottom:0">{{ session('status') }}</div></div>
                @endif

                @yield('content')
            </div>
        </main>

        @stack('scripts')
    </body>
</html>
