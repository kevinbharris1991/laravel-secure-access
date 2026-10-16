<?php

namespace SecureAccess\Sms;

use Illuminate\Support\Carbon;
use SecureAccess\Contracts\SmsSender;

/**
 * Issues and verifies one-time codes delivered by text message. Codes are stored hashed in the session.
 */
class SmsCodeManager
{
    protected const MAX_ATTEMPTS = 5;

    public function __construct(protected SmsSender $sender)
    {
    }

    /**
     * Send a new code. Returns false when a code was sent too recently.
     */
    public function send(string $key, string $phoneNumber): bool
    {
        $state = session($this->sessionKey($key));
        $resendAfter = (int) config('secure-access.mfa.sms.resend_after', 30);

        if (is_array($state) && ($state['phone'] ?? null) === $phoneNumber
            && ($state['sent_at'] ?? 0) + $resendAfter > Carbon::now()->getTimestamp()) {
            return false;
        }

        $length = max(4, (int) config('secure-access.mfa.sms.code_length', 6));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        session()->put($this->sessionKey($key), [
            'phone' => $phoneNumber,
            'hash' => $this->hash($code),
            'sent_at' => Carbon::now()->getTimestamp(),
            'expires_at' => Carbon::now()->addMinutes((int) config('secure-access.mfa.sms.expires', 10))->getTimestamp(),
            'attempts' => 0,
        ]);

        $this->sender->send($phoneNumber, __(':app verification code: :code', [
            'app' => config('app.name'),
            'code' => $code,
        ]));

        return true;
    }

    public function hasPendingCode(string $key): bool
    {
        $state = session($this->sessionKey($key));

        return is_array($state) && $state['expires_at'] >= Carbon::now()->getTimestamp();
    }

    public function phone(string $key): ?string
    {
        return session($this->sessionKey($key))['phone'] ?? null;
    }

    /**
     * Verify the code. A successful verification consumes it.
     */
    public function verify(string $key, string $code, ?string $phoneNumber = null): bool
    {
        $state = session($this->sessionKey($key));

        if (! is_array($state) || $state['expires_at'] < Carbon::now()->getTimestamp()) {
            return false;
        }

        if ($phoneNumber !== null && ! hash_equals($state['phone'], $phoneNumber)) {
            return false;
        }

        if (hash_equals($state['hash'], $this->hash(preg_replace('/\s+/', '', $code)))) {
            $this->forget($key);

            return true;
        }

        $state['attempts']++;

        $state['attempts'] >= self::MAX_ATTEMPTS
            ? $this->forget($key)
            : session()->put($this->sessionKey($key), $state);

        return false;
    }

    public function forget(string $key): void
    {
        session()->forget($this->sessionKey($key));
    }

    protected function sessionKey(string $key): string
    {
        return 'secure_access.sms.'.$key;
    }

    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
