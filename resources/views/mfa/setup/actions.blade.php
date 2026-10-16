<div class="sa-actions sa-between">
    <a class="sa-link" href="{{ route('secure-access.setup') }}">{{ __('Choose another method') }}</a>

    @if ($submit)
        <button type="submit" class="sa-btn">{{ $submit }}</button>
    @endif
</div>
