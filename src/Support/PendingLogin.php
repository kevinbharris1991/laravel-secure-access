<?php

namespace SecureAccess\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SecureAccess\Models\MfaMethod;
use SecureAccess\SecureAccess;

/**
 * Tracks a user that passed the password step but has not completed multi-factor authentication yet.
 */
class PendingLogin
{
    protected const KEY = 'secure_access.pending';

    public function __construct(protected AccountLockout $lockout)
    {
    }

    public function start(Model $user): void
    {
        session()->put(self::KEY, [
            'user_id' => (string) $user->getKey(),
            'expires_at' => Carbon::now()->addMinutes((int) config('secure-access.mfa.pending_login_timeout', 10))->getTimestamp(),
        ]);
    }

    public function user(): ?Model
    {
        $pending = session(self::KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            return null;
        }

        return SecureAccess::newUserQuery()->find($pending['user_id']);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    public function hasMethods(Model $user): bool
    {
        return MfaMethod::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Log the user in after every factor has been verified.
     */
    public function complete(Model $user): void
    {
        $this->clear();

        SecureAccess::guard()->login($user);

        session()->regenerate();
        session()->put(SecureAccess::MFA_VERIFIED_SESSION_KEY, (string) $user->getAuthIdentifier());

        $this->lockout->clearFailures($user);
    }
}
