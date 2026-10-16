<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use SecureAccess\Models\MfaMethod;
use SecureAccess\SecureAccess;
use SecureAccess\Sms\SmsCodeManager;
use SecureAccess\Support\PendingLogin;
use SecureAccess\Totp\Totp;
use SecureAccess\WebAuthn\WebAuthn;
use SecureAccess\WebAuthn\WebAuthnException;

/**
 * Registers multi-factor methods. Used both to force enrollment on the first login
 * (pending login without any method) and by signed-in users adding more methods.
 */
class SetupController extends Controller
{
    protected const TOTP_SESSION_KEY = 'secure_access.setup.totp_secret';

    protected const SMS_KEY = 'setup';

    public function __construct(
        protected PendingLogin $pending,
        protected SmsCodeManager $sms,
        protected Totp $totp,
        protected WebAuthn $webauthn,
    ) {
    }

    public function index(): View|RedirectResponse
    {
        [$user, $enrolling] = $this->context();

        if (! $user) {
            return $this->unauthorized();
        }

        return view('secure-access::mfa.setup.index', [
            'types' => SecureAccess::enabledMethods(),
            'enrolling' => $enrolling,
        ]);
    }

    public function create(string $type): View|RedirectResponse
    {
        $this->ensureEnabled($type);
        [$user, $enrolling] = $this->context();

        if (! $user) {
            return $this->unauthorized();
        }

        $data = ['type' => $type, 'enrolling' => $enrolling];

        if ($type === MfaMethod::TYPE_TOTP) {
            $secret = session(self::TOTP_SESSION_KEY) ?: tap($this->totp->generateSecret(), fn ($s) => session()->put(self::TOTP_SESSION_KEY, $s));
            $uri = $this->totp->provisioningUri($secret, (string) ($user->getAttribute(config('secure-access.email_column', 'email')) ?: $user->getKey()));
            $data += ['secret' => $secret, 'qrCode' => $this->totp->qrCodeSvg($uri), 'uri' => $uri];
        }

        if ($type === MfaMethod::TYPE_SMS) {
            $data['phone'] = $this->sms->hasPendingCode(self::SMS_KEY) ? $this->sms->phone(self::SMS_KEY) : null;
        }

        if (in_array($type, [MfaMethod::TYPE_BIOMETRIC, MfaMethod::TYPE_SECURITY_KEY], true)) {
            $data['options'] = $this->webauthn->registrationOptions($user, $type, $this->methods($user));
        }

        return view('secure-access::mfa.setup.create', $data);
    }

    public function sendSms(Request $request): RedirectResponse
    {
        $this->ensureEnabled(MfaMethod::TYPE_SMS);
        [$user] = $this->context();

        if (! $user) {
            return $this->unauthorized();
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ], [
            'phone.regex' => __('Enter the phone number in international format, e.g. +15555550123.'),
        ]);

        $sent = $this->sms->send(self::SMS_KEY, $validated['phone']);

        return redirect()->route('secure-access.setup.create', MfaMethod::TYPE_SMS)->with('status', $sent
            ? __('A verification code has been sent to :phone.', ['phone' => $validated['phone']])
            : __('Please wait a few seconds before requesting another code.'));
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $this->ensureEnabled($type);
        [$user, $enrolling] = $this->context();

        if (! $user) {
            return $this->unauthorized();
        }

        $request->validate(['name' => ['nullable', 'string', 'max:100']]);

        $attributes = match ($type) {
            MfaMethod::TYPE_TOTP => $this->storeTotp($request),
            MfaMethod::TYPE_SMS => $this->storeSms($request),
            default => $this->storeWebAuthn($request, $user, $type),
        };

        $method = MfaMethod::create($attributes + [
            'user_id' => $user->getKey(),
            'type' => $type,
            'name' => $request->input('name') ?: MfaMethod::typeLabel($type),
        ]);

        if ($enrolling) {
            // Registering a method proves possession of the new factor, completing this login.
            $method->forceFill(['last_used_at' => now()])->save();
            $this->pending->complete($user);

            return redirect()->intended(config('secure-access.home', '/'))
                ->with('status', __('Multi-factor authentication has been set up.'));
        }

        return redirect()->route('secure-access.security')
            ->with('status', __(':method added.', ['method' => MfaMethod::typeLabel($type)]));
    }

    protected function storeTotp(Request $request): array
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $secret = session(self::TOTP_SESSION_KEY);
        $step = $secret ? $this->totp->verify($secret, $request->input('code')) : null;

        if ($step === null) {
            throw ValidationException::withMessages(['code' => __('The provided code is invalid.')]);
        }

        session()->forget(self::TOTP_SESSION_KEY);

        return ['secret' => $secret, 'counter' => $step];
    }

    protected function storeSms(Request $request): array
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        $phone = $this->sms->phone(self::SMS_KEY);

        if (! $phone || ! $this->sms->verify(self::SMS_KEY, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('The provided code is invalid.')]);
        }

        return ['secret' => $phone];
    }

    protected function storeWebAuthn(Request $request, Model $user, string $type): array
    {
        $request->validate(['credential' => ['required', 'string', 'max:65535']]);

        $credential = json_decode($request->input('credential'), true);

        try {
            if (! is_array($credential)) {
                throw new WebAuthnException('Invalid credential.');
            }

            $attributes = $this->webauthn->verifyRegistration($user, $type, $credential);
        } catch (WebAuthnException $e) {
            throw ValidationException::withMessages(['credential' => __('Registration failed: :reason', ['reason' => $e->getMessage()])]);
        }

        if (MfaMethod::query()->where('credential_hash', MfaMethod::hashCredentialId($attributes['credential_id']))->exists()) {
            throw ValidationException::withMessages(['credential' => __('This device is already registered.')]);
        }

        return $attributes;
    }

    /**
     * Resolve the user registering a method and whether this is the forced first-login enrollment.
     *
     * @return array{0: Model|null, 1: bool}
     */
    protected function context(): array
    {
        $user = SecureAccess::guard()->user();

        if ($user && SecureAccess::mfaVerified($user)) {
            return [$user, false];
        }

        $pending = $this->pending->user();

        if ($pending && ! $this->pending->hasMethods($pending)) {
            return [$pending, true];
        }

        return [null, false];
    }

    protected function methods(Model $user)
    {
        return MfaMethod::query()->where('user_id', $user->getKey())->get();
    }

    protected function ensureEnabled(string $type): void
    {
        abort_unless(in_array($type, SecureAccess::enabledMethods(), true), 404);
    }

    protected function unauthorized(): RedirectResponse
    {
        return $this->pending->user()
            ? redirect()->route('secure-access.challenge')
            : redirect()->route('secure-access.login');
    }
}
