@if (\Illuminate\Support\Facades\Route::has('secure-access.messaging.index') && ($messagingUser = \SecureAccess\SecureAccess::guard()->user()) && in_array($messagingUser->getAttribute('registration_role'), ['self_advocate', 'family_caregiver', 'professional'], true) && \SecureAccess\SecureAccess::mfaVerified($messagingUser))
    @php($messagingUnread = app(\SecureAccess\Services\TeamMessagingService::class)->unreadCount((string) $messagingUser->getAuthIdentifier()))
    <aside aria-label="{{ __('Team messenger') }}" style="position:fixed;bottom:1rem;right:1rem;z-index:20">
        <a class="sa-btn" id="sa-messages-open" href="{{ route('secure-access.messaging.index') }}">
            {{ __('Messages') }} <span id="sa-messages-unread" aria-label="{{ __(':count unread messages', ['count' => $messagingUnread]) }}">({{ $messagingUnread }})</span>
        </a>
    </aside>
    <dialog id="sa-messages-dialog" aria-labelledby="sa-messages-title" style="width:min(46rem,95vw);max-height:90vh;border:1px solid #64748b;border-radius:.5rem;padding:1rem">
        <h2 id="sa-messages-title">{{ __('Team messages') }}</h2>
        <form method="dialog"><button class="sa-btn" autofocus>{{ __('Close messages') }}</button></form>
        <p><a class="sa-link" href="{{ route('secure-access.messaging.index') }}">{{ __('Open full-page messenger') }}</a></p>
        <iframe id="sa-messages-frame" title="{{ __('Private team messenger') }}" data-src="{{ route('secure-access.messaging.index', ['embedded' => 1]) }}" style="width:100%;height:60vh;border:0"></iframe>
    </dialog>
    <script>
        (() => {
            const open = document.getElementById('sa-messages-open');
            const dialog = document.getElementById('sa-messages-dialog');
            const frame = document.getElementById('sa-messages-frame');
            const badge = document.getElementById('sa-messages-unread');
            if (!dialog.showModal) return;
            open.addEventListener('click', event => {
                event.preventDefault();
                frame.src = frame.dataset.src;
                dialog.showModal();
            });
            dialog.addEventListener('close', () => {
                frame.removeAttribute('src');
                open.focus();
            });
            frame.addEventListener('load', () => {
                if (!frame.getAttribute('src')) return;
                const unread = frame.contentDocument.body.dataset.messagingUnread;
                if (unread !== undefined) {
                    badge.textContent = '(' + unread + ')';
                    badge.setAttribute('aria-label', @json(__(':count unread messages')).replace(':count', unread));
                }
                frame.contentDocument.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        dialog.close();
                    }
                });
            });
        })();
    </script>
@endif
