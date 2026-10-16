@extends('secure-access::layouts.app')

@section('title', $definition['title'])
@section('header', $definition['title'])

@section('content')
    <div class="sa-section">
        <a class="sa-link" href="{{ route('secure-access.camp.index', $module) }}">{{ __('Back to records') }}</a>
        @if ($module === 'special_meals')
            <p class="sa-text">{{ __('Guest') }}: {{ $guestName ?? $record->owner_id }}</p>
        @endif
        @foreach ($definition['fields'] as $field => $rules)
            <h3 class="sa-title sa-mt-4">{{ str($field)->replace('_', ' ')->title() }}</h3>
            <p class="sa-text sa-record-value">{{ is_scalar($record->data[$field] ?? '') ? ($record->data[$field] ?? '') : json_encode($record->data[$field]) }}</p>
        @endforeach
        @if (isset($record->data['status']))
            <p class="sa-text sa-mt-4">{{ __('Status') }}: {{ $record->data['status'] }}</p>
        @endif
        @include('secure-access::partials.errors', ['messages' => $errors->all()])
        <div class="sa-actions">
            @foreach ($permissions as $action => $permission)
                @can('secure-access', [$permission, $subject])
                    @if ($action === 'update')
                        <a class="sa-btn" href="{{ route('secure-access.camp.edit', [$module, $record->id]) }}">{{ __('Edit') }}</a>
                    @elseif ($action === 'delete')
                        <form method="POST" action="{{ route('secure-access.camp.destroy', [$module, $record->id]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="sa-btn sa-btn-danger" type="submit">{{ __('Delete') }}</button>
                        </form>
                    @elseif (! in_array($action, ['create', 'read', 'export']))
                        <form method="POST" action="{{ route('secure-access.camp.action', [$module, $record->id, $action]) }}">
                            @csrf
                            @if ($action === 'assign')
                                <label class="sa-label" for="assignee_id">{{ __('Staff account ID') }}</label>
                                <input class="sa-input" type="number" min="1" id="assignee_id" name="assignee_id" required>
                                @if ($module === 'rosters')
                                    <label class="sa-label" for="camper_id">{{ __('Camper record ID') }}</label>
                                    <input class="sa-input" type="number" min="1" id="camper_id" name="camper_id" required>
                                @endif
                            @endif
                            <button class="sa-btn" type="submit">{{ str($action)->replace('_', ' ')->title() }}</button>
                        </form>
                    @endif
                @endcan
            @endforeach
        </div>
    </div>
@endsection
