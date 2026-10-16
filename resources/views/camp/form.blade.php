@extends('secure-access::layouts.app')

@section('title', $definition['title'])
@section('header', $definition['title'])

@section('content')
    <div class="sa-section">
        @include('secure-access::partials.errors', ['messages' => $errors->all()])
        @php($action = $record ? 'update' : 'create')
        @if (isset($permissions[$action]))
            @can('secure-access', [$permissions[$action], $subject])
                <form method="POST" action="{{ $record ? route('secure-access.camp.update', [$module, $record->id]) : route('secure-access.camp.store', $module) }}">
                    @csrf
                    @if ($record)
                        @method('PUT')
                    @endif
                    @if (! $record && (str_ends_with($permissions[$action], '_assigned') || (! str_ends_with($permissions[$action], '_own') && in_array($module, ['waivers', 'consents', 'households', 'medications', 'medication_stock', 'dosages', 'health_profiles', 'clinical_protocols', 'clinical_incidents', 'care_plans', 'allergy_alerts', 'safety_alerts', 'behavior_health_context', 'support_plans', 'behavior_strategies', 'behavior_logs', 'consultation_notes', 'participation']))))
                        <label class="sa-label sa-mt-4" for="camper_id">{{ __('Camper record ID') }}</label>
                        <input class="sa-input" id="camper_id" name="camper_id" type="number" min="1" value="{{ old('camper_id', $record?->camper_id) }}" @required(str_ends_with($permissions[$action], '_assigned'))>
                    @endif
                    @if (! $record && $module === 'booking_balances')
                        <label class="sa-label sa-mt-4" for="guest_id">{{ __('Guest account ID') }}</label>
                        <input class="sa-input" id="guest_id" name="guest_id" value="{{ old('guest_id') }}" required>
                    @endif
                    @if (in_array($module, ['meal_plans', 'activities', 'events']))
                        <label class="sa-label sa-mt-4" for="is_public">{{ __('Publish for all guests') }}</label>
                        <select class="sa-input" id="is_public" name="is_public">
                            <option value="0" @selected(! old('is_public', $record?->is_public ?? false))>{{ __('Draft') }}</option>
                            <option value="1" @selected(old('is_public', $record?->is_public ?? false))>{{ __('Published') }}</option>
                        </select>
                    @endif
                    @foreach ($definition['fields'] as $field => $rules)
                        <label class="sa-label sa-mt-4" for="{{ $field }}">{{ $field === 'allergens' ? __('Food allergens') : str($field)->replace('_', ' ')->title() }}</label>
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
