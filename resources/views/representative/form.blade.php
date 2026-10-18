<form method="POST" action="{{ $record ? route('secure-access.representative.update', [$relationship, $module, $record]) : route('secure-access.representative.store', [$relationship, $module]) }}">
    @csrf
    @if ($record)
        @method('PUT')
    @endif
    @foreach ($definition['fields'] as $field => $rules)
        @php($inputId = 'representative-'.($record?->id ?? 'new').'-'.$field)
        <label class="sa-label sa-mt-4" for="{{ $inputId }}">{{ str($field)->replace('_', ' ')->title() }}</label>
        @if (preg_match('/(?:^|\|)in:([^|]+)/', $rules, $options))
            <select class="sa-input" id="{{ $inputId }}" name="{{ $field }}">
                @foreach (explode(',', $options[1]) as $option)
                    <option value="{{ $option }}" @selected(old($field, $record?->data[$field] ?? '') === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        @elseif (str_contains($rules, 'date_format:Y-m-d'))
            <input class="sa-input" id="{{ $inputId }}" name="{{ $field }}" type="date" value="{{ old($field, $record?->data[$field] ?? '') }}">
        @elseif (str_contains($rules, 'numeric'))
            <input class="sa-input" id="{{ $inputId }}" name="{{ $field }}" type="number" min="0" step="0.01" value="{{ old($field, $record?->data[$field] ?? '') }}">
        @else
            <textarea class="sa-input" id="{{ $inputId }}" name="{{ $field }}" rows="2">{{ old($field, $record?->data[$field] ?? '') }}</textarea>
        @endif
    @endforeach
    <button class="sa-btn sa-mt-4" type="submit">{{ $record ? __('Save changes') : __('Create') }}</button>
</form>
