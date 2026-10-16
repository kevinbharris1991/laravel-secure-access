{!! $greeting !!}

@foreach ($introLines as $line)
{!! $line !!}

@endforeach
{!! $actionText !!}:
{!! $actionUrl !!}

@foreach ($outroLines as $line)
{!! $line !!}

@endforeach
{!! __('Regards,') !!}
{!! config('app.name') !!}
