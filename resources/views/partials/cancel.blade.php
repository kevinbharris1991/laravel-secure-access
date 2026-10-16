<form method="POST" action="{{ route('secure-access.logout') }}" class="sa-inline">
    @csrf
    <button type="submit" class="sa-link">{{ __('Cancel') }}</button>
</form>
