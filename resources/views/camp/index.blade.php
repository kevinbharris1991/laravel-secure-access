@extends('secure-access::layouts.app')

@section('title', $definition['title'])
@section('header', $definition['title'])

@section('content')
    <div class="sa-section">
        <p class="sa-text">{{ __('Only records within your authorized scope are shown.') }}</p>
        <div class="sa-actions">
            @if (isset($permissions['create']))
                @can('secure-access', [$permissions['create'], $subject])
                    <a class="sa-btn" href="{{ route('secure-access.camp.create', $module) }}">{{ __('Create record') }}</a>
                @endcan
            @endif
            @if (isset($permissions['export']))
                @can('secure-access', [$permissions['export'], $subject])
                    <a class="sa-btn" href="{{ route('secure-access.camp.export', $module) }}">{{ __('Export report') }}</a>
                @endcan
            @endif
        </div>
    </div>

    @if (isset($summary))
        <div class="sa-section">
            <h2 class="sa-title">{{ __('Summary') }}</h2>
            @foreach ($summary as $label => $value)
                <p class="sa-text">{{ str($label)->replace('_', ' ')->title() }}:
                    {{ is_scalar($value) ? $value : json_encode($value) }}
                </p>
            @endforeach
        </div>
    @endif

    @foreach ($records as $record)
        <div class="sa-section">
            <h2 class="sa-title">{{ $definition['title'] }} #{{ $record->id }}</h2>
            @if (! $definition['aggregate'])
                <p class="sa-text">{{ $record->data['title'] ?? $record->data['name'] ?? '' }}</p>
            @endif
            @if ($definition['aggregate'])
                @foreach ($record->data as $field => $value)
                    <p class="sa-text">{{ str($field)->replace('_', ' ')->title() }}: {{ $value }}</p>
                @endforeach
            @else
                @php($readPermission = \SecureAccess\Camp\Modules::permission(\SecureAccess\SecureAccess::guard()->user(), $module, 'read', $record))
                @if ($readPermission)
                    @can('secure-access', [$readPermission, $record])
                        <a class="sa-btn sa-mt-4" href="{{ route('secure-access.camp.show', [$module, $record->id]) }}">{{ __('View record') }}</a>
                    @endcan
                @endif
            @endif
        </div>
    @endforeach
    @if (method_exists($records, 'links'))
        {{ $records->links() }}
    @endif
@endsection
