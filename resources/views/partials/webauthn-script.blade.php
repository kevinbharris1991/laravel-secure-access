@once
    @push('scripts')
        <script>
            (() => {
                const toBuffer = (value) => {
                    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
                    const binary = atob(base64 + '='.repeat((4 - base64.length % 4) % 4));
                    return Uint8Array.from(binary, (c) => c.charCodeAt(0)).buffer;
                };
                const toBase64Url = (buffer) => {
                    let binary = '';
                    new Uint8Array(buffer).forEach((b) => binary += String.fromCharCode(b));
                    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                };
                const showError = (form, message) => {
                    const error = form.querySelector('[data-webauthn-error]');
                    error.querySelector('li').textContent = message;
                    error.hidden = false;
                };

                document.querySelectorAll('form[data-webauthn]').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        if (form.dataset.ready === '1') {
                            return;
                        }
                        event.preventDefault();

                        if (!window.PublicKeyCredential) {
                            showError(form, @json(__('This browser does not support biometrics or security keys.')));
                            return;
                        }

                        const options = JSON.parse(form.querySelector('[data-webauthn-options]').textContent);
                        options.challenge = toBuffer(options.challenge);
                        const descriptors = (list) => (list || []).map((c) => ({ ...c, id: toBuffer(c.id) }));

                        try {
                            let credential;
                            if (form.dataset.webauthn === 'create') {
                                options.user.id = toBuffer(options.user.id);
                                options.excludeCredentials = descriptors(options.excludeCredentials);
                                credential = await navigator.credentials.create({ publicKey: options });
                            } else {
                                options.allowCredentials = descriptors(options.allowCredentials);
                                credential = await navigator.credentials.get({ publicKey: options });
                            }

                            const response = credential.response;
                            const payload = {
                                id: credential.id,
                                rawId: toBase64Url(credential.rawId),
                                type: credential.type,
                                response: { clientDataJSON: toBase64Url(response.clientDataJSON) },
                            };

                            if (response.attestationObject) {
                                payload.response.attestationObject = toBase64Url(response.attestationObject);
                                payload.response.transports = response.getTransports ? response.getTransports() : [];
                            } else {
                                payload.response.authenticatorData = toBase64Url(response.authenticatorData);
                                payload.response.signature = toBase64Url(response.signature);
                                payload.response.userHandle = response.userHandle ? toBase64Url(response.userHandle) : null;
                            }

                            form.querySelector('input[name="credential"]').value = JSON.stringify(payload);
                            form.dataset.ready = '1';
                            form.submit();
                        } catch (error) {
                            showError(form, @json(__('The operation was cancelled or timed out. Please try again.')));
                        }
                    });
                });
            })();
        </script>
    @endpush
@endonce
