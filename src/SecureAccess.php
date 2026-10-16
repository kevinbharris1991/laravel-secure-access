<?php

namespace SecureAccess;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SecureAccess
{
    /**
     * Session key holding the identifier of the user that passed multi-factor authentication.
     */
    public const MFA_VERIFIED_SESSION_KEY = 'secure_access.mfa_verified';

    /**
     * Get the class name of the user model.
     *
     * @return class-string<Model&Authenticatable>
     */
    public static function userModel(): string
    {
        return config('secure-access.user_model') ?: config('auth.providers.users.model', 'App\\Models\\User');
    }

    /**
     * Start a new query for the user model.
     */
    public static function newUserQuery(): Builder
    {
        $model = static::userModel();

        return (new $model)->newQuery();
    }

    /**
     * Get the guard used to log users in.
     */
    public static function guard(): StatefulGuard
    {
        return Auth::guard(config('secure-access.guard'));
    }

    /**
     * Get the enabled multi-factor method types.
     *
     * @return array<int, string>
     */
    public static function enabledMethods(): array
    {
        return array_values(array_intersect(
            Models\MfaMethod::TYPES,
            (array) config('secure-access.mfa.methods', Models\MfaMethod::TYPES)
        ));
    }

    /**
     * Determine whether the current session passed multi-factor authentication for the given user.
     */
    public static function mfaVerified(Authenticatable $user): bool
    {
        return session(static::MFA_VERIFIED_SESSION_KEY) === (string) $user->getAuthIdentifier();
    }
}
