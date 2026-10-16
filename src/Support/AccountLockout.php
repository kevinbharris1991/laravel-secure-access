<?php

namespace SecureAccess\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SecureAccess\Events\AccountLocked;

/**
 * Locks an account after too many consecutive failed login attempts.
 */
class AccountLockout
{
    public function maxAttempts(): int
    {
        return max(1, (int) config('secure-access.lockout.max_attempts', 3));
    }

    public function isLocked(Model $user): bool
    {
        $lockedAt = $user->getAttribute('locked_at');

        if ($lockedAt === null) {
            return false;
        }

        $duration = config('secure-access.lockout.duration');

        if ($duration === null || $duration === '') {
            return true;
        }

        if (Carbon::parse($lockedAt)->addMinutes((int) $duration)->isFuture()) {
            return true;
        }

        $this->unlock($user);

        return false;
    }

    /**
     * Record a failed attempt. Returns true when the account is (now) locked.
     */
    public function recordFailure(Model $user): bool
    {
        $query = $user->newQuery()->whereKey($user->getKey())->toBase();

        $query->increment('failed_login_attempts');

        $attempts = (int) $user->newQuery()->whereKey($user->getKey())->toBase()->value('failed_login_attempts');

        $user->forceFill(['failed_login_attempts' => $attempts])->syncOriginalAttribute('failed_login_attempts');

        if ($attempts < $this->maxAttempts()) {
            return false;
        }

        $now = Carbon::now();

        $newlyLocked = $user->newQuery()->whereKey($user->getKey())->whereNull('locked_at')->toBase()->update(['locked_at' => $now]) > 0;

        $user->setAttribute('locked_at', $user->newQuery()->whereKey($user->getKey())->toBase()->value('locked_at'));
        $user->syncOriginalAttribute('locked_at');

        if ($newlyLocked) {
            event(new AccountLocked($user));
        }

        return true;
    }

    public function remainingAttempts(Model $user): int
    {
        return max(0, $this->maxAttempts() - (int) $user->getAttribute('failed_login_attempts'));
    }

    /**
     * Reset the failure counter (after a fully successful login) without unlocking.
     */
    public function clearFailures(Model $user): void
    {
        $user->newQuery()->whereKey($user->getKey())->toBase()->update(['failed_login_attempts' => 0]);
        $user->forceFill(['failed_login_attempts' => 0])->syncOriginalAttribute('failed_login_attempts');
    }

    public function unlock(Model $user): void
    {
        $user->newQuery()->whereKey($user->getKey())->toBase()->update([
            'failed_login_attempts' => 0,
            'locked_at' => null,
        ]);

        $user->forceFill(['failed_login_attempts' => 0, 'locked_at' => null])
            ->syncOriginalAttributes(['failed_login_attempts', 'locked_at']);
    }
}
