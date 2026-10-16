<?php

namespace SecureAccess\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Models\UserEmail;

/**
 * Optional convenience trait for the application's User model.
 */
trait HasSecureAccess
{
    public function emails(): HasMany
    {
        return $this->hasMany(UserEmail::class, 'user_id');
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(MfaMethod::class, 'user_id');
    }

    public function isLocked(): bool
    {
        return app(\SecureAccess\Support\AccountLockout::class)->isLocked($this);
    }
}
