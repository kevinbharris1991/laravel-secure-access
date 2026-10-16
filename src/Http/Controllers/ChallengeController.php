<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Sms\SmsCodeManager;
use SecureAccess\Support\AccountLockout;
use SecureAccess\Support\PendingLogin;
use SecureAccess\Totp\Totp;
use SecureAccess\WebAuthn\WebAuthn;
use SecureAccess\WebAuthn\WebAuthnException;

/**
 * Second login step: the user must use one of their registered methods.
 */
class ChallengeController extends Controller
{
    public function __construct(
        protected PendingLogin $pending,
        protected AccountLockout $lockout,
        protected SmsCodeManager $sms,
        protected Totp $totp,
        protected WebAuthn $webauthn,
    ) {
    }

    public function index(): View|RedirectResponse
    {
        if (! $user = $this->pendingUser()) {
            return $this->expired();
        }

        if ($this->lockout->isLocked($user)) {
            return $this->locked();
        }

        $methods = $this->methods($user);

        if ($methods->isEmpty()) {
            return redirect()->route('secure-access.setup');
        }

        if ($methods->count() === 1) {
            return redirect()->route('secure-access.challenge.show', $methods->first());
        }

        return view('secure-access::mfa.challenge.index', ['methods' => $methods]);
    }

    public function show(MfaMethod $method): View|RedirectResponse
    {
        if (! $user = $this->pendingUser()) {
            return $this->expired();
        }

        $this->authorizeMethod($user, $method);

        if ($this->lockout->isLocked($user)) {
            return $this->locked();
        }

        $data = [
            'method' => $method,
            'hasOtherMethods' => $this->methods($user)->count() > 1,
        ];

        if ($method->isWebAuthn()) {
            $data['options'] = $this->webauthn->assertionOptions($user, $method->type, collect([$method]));
        }

        if ($method->type === MfaMethod::TYPE_SMS) {
            $data['codeSent'] = $this->sms->hasPendingCode($this->smsKey($method));
        }

        return view('secure-access::mfa.challenge.show', $data);
    }

    public function send(MfaMethod $method): RedirectResponse
    {
        if (! $user = $this->pendingUser()) {
            return $this->expired();
        }

        $this->authorizeMethod($user, $method);
        abort_unless($method->type === MfaMethod::TYPE_SMS, 404);

        if ($this->lockout->isLocked($user)) {
            return $this->locked();
        }

        $sent = $this->sms->send($this->smsKey($method), $method->secret);

        return redirect()->route('secure-access.challenge.show', $method)->with('status', $sent
            ? __('A verification code has been sent to :phone.', ['phone' => $method->maskedPhone()])
            : __('Please wait a few seconds before requesting another code.'));
    }

    public function verify(Request $request, MfaMethod $method): RedirectResponse
    {
        if (! $user = $this->pendingUser()) {
            return $this->expired();
        }

        $this->authorizeMethod($user, $method);

        if ($this->lockout->isLocked($user)) {
            return $this->locked();
        }

        $verified = match ($method->type) {
            MfaMethod::TYPE_TOTP => $this->verifyTotp($request, $method),
            MfaMethod::TYPE_SMS => $this->verifySms($request, $method),
            default => $this->verifyWebAuthn($request, $user, $method),
        };

        if ($verified instanceof RedirectResponse) {
            return $verified;
        }

        if (! $verified) {
            if ($this->lockout->recordFailure($user)) {
                return $this->locked();
            }

            return redirect()->route('secure-access.challenge.show', $method)->withErrors([
                'code' => $method->isWebAuthn()
                    ? __('We could not verify your :method. Please try again.', ['method' => mb_strtolower($method->label())])
                    : __('The provided code is invalid.'),
            ]);
        }

        $method->forceFill(['last_used_at' => now()])->save();
        $this->pending->complete($user);

        return redirect()->intended(config('secure-access.home', '/'));
    }

    protected function verifyTotp(Request $request, MfaMethod $method): bool
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $step = $this->totp->verify($method->secret, $request->input('code'), $method->counter);

        if ($step === null) {
            return false;
        }

        // Atomically mark the time step as used so a code can never be replayed.
        return MfaMethod::query()->whereKey($method->getKey())->where('counter', '<', $step)->update(['counter' => $step]) === 1;
    }

    protected function verifySms(Request $request, MfaMethod $method): bool|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        if (! $this->sms->hasPendingCode($this->smsKey($method))) {
            return redirect()->route('secure-access.challenge.show', $method)
                ->withErrors(['code' => __('Your code has expired. Please request a new code.')]);
        }

        return $this->sms->verify($this->smsKey($method), $request->input('code'), $method->secret);
    }

    protected function verifyWebAuthn(Request $request, Model $user, MfaMethod $method): bool
    {
        $request->validate(['credential' => ['required', 'string', 'max:65535']]);

        $credential = json_decode($request->input('credential'), true);

        if (! is_array($credential)) {
            return false;
        }

        try {
            $this->webauthn->verifyAssertion($user, $method->type, collect([$method]), $credential);
        } catch (WebAuthnException) {
            return false;
        }

        return true;
    }

    protected function pendingUser(): ?Model
    {
        return $this->pending->user();
    }

    protected function methods(Model $user)
    {
        return MfaMethod::query()->where('user_id', $user->getKey())->orderBy('id')->get();
    }

    protected function authorizeMethod(Model $user, MfaMethod $method): void
    {
        abort_unless((string) $method->user_id === (string) $user->getKey(), 404);
    }

    protected function smsKey(MfaMethod $method): string
    {
        return 'challenge.'.$method->getKey();
    }

    protected function expired(): RedirectResponse
    {
        $this->pending->clear();

        return redirect()->route('secure-access.login')
            ->withErrors(['identifier' => __('Your login session has expired. Please sign in again.')]);
    }

    protected function locked(): RedirectResponse
    {
        $this->pending->clear();

        return redirect()->route('secure-access.login')->withErrors(['identifier' => AccountLockout::lockedMessage()]);
    }
}
