<div>
    <label class="sa-label" for="name">{{ __('Name') }}</label>
    <input id="name" class="sa-input" type="text" name="name" value="{{ old('name') }}" maxlength="100" placeholder="{{ \SecureAccess\Models\MfaMethod::typeLabel($type) }}">
    @include('secure-access::partials.errors', ['messages' => $errors->get('name')])
</div>
