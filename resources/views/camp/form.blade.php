@extends('secure-access::layouts.app')

@section('title', $definition['title'])
@section('header', $definition['title'])

@section('content')
    <div class="sa-section">
        @include('secure-access::partials.errors')
        @php($action = $record ? 'update' : 'create')
        @if (isset($permissions[$action]))
            @can('secure-access', [$permissions[$action], $subject])
                <form method="POST" action="{{ $record ? route('secure-access.camp.update', [$module, $record->id]) : route('secure-access.camp.store', $module) }}">
                    @csrf
                    @if ($record)
                        @method('PUT')
                    @endif
                    @if (! $record && str_ends_with($permissions[$action], '_assigned'))
                        <label class="sa-label sa-mt-4" for="camper_id">{{ __('Camper record ID') }}</label>
                        <input class="sa-input" id="camper_id" name="camper_id" type="number" min="1" value="{{ old('camper_id', $record?->camper_id) }}" required>
                    @endif
                    @foreach ($definition['fields'] as $field => $rules)
                        <label class="sa-label sa-mt-4" for="{{ $field }}">{{ str($field)->replace('_', ' ')->title() }}</label>
                        @if (str_contains($rules, 'boolean'))
                            <select class="sa-input" id="{{ $field }}" name="{{ $field }}">
                                <option value="0" @selected(! old($field, $record?->data[$field] ?? false))>{{ __('No') }}</option>
                                <option value="1" @selected(old($field, $record?->data[$field] ?? false))>{{ __('Yes') }}</option>
                            </select>
                        @elseif (preg_match('/(?:^|\\|)in:([^|]+)/', $rules, $options))
                            <select class="sa-input" id="{{ $field }}" name="{{ $field }}">
                                @foreach (explode(',', $options[1]) as $option)
                                    <option value="{{ $option }}" @selected((string) old($field, $record?->data[$field] ?? '') === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                                @endforeach
                            </select>
                        @elseif (str_contains($rules, 'date_format:Y-m-d'))
                            <input class="sa-input" id="{{ $field }}" name="{{ $field }}" type="date" value="{{ old($field, $record?->data[$field] ?? '') }}">
                        @elseif (str_contains($rules, 'numeric') || str_contains($rules, 'integer'))
                            <input class="sa-input" id="{{ $field }}" name="{{ $field }}" type="number" step="{{ str_contains($rules, 'integer') ? '1' : '0.01' }}" value="{{ old($field, $record?->data[$field] ?? '') }}">
                        @else
                            <textarea class="sa-input" id="{{ $field }}" name="{{ $field }}" rows="{{ str_contains($field, 'notes') ? 4 : 2 }}">{{ old($field, $record?->data[$field] ?? '') }}</textarea>
                        @endif
                    @endforeach
                    <div class="sa-actions">
                        <a class="sa-link" href="{{ route('secure-access.camp.index', $module) }}">{{ __('Back') }}</a>
                        <button class="sa-btn" type="submit">{{ __('Save') }}</button>
                    </div>
                </form>
            @endcan
        @endif
    </div>
@endsection
