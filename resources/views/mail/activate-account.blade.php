<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
</head>
<body>
    <h1>{{ $greeting }}</h1>
    @foreach ($introLines as $line)
        <p>{{ $line }}</p>
    @endforeach

    <p><a href="{{ $actionUrl }}">{{ $actionText }}</a></p>

    @foreach ($outroLines as $line)
        <p>{{ $line }}</p>
    @endforeach

    <p>{{ __('If the button does not work, copy and paste this URL into your browser:') }}</p>
    <p><a href="{{ $actionUrl }}">{{ $actionUrl }}</a></p>

    <p>{{ __('Regards,') }}<br>{{ config('app.name') }}</p>
</body>
</html>
