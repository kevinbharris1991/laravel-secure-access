<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A registration whose email address has not been confirmed through the activation link yet.
 *
 * @property int $id
 * @property string|null $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $date_of_birth
 * @property string|null $gender
 * @property string|null $registration_role
 * @property string $token SHA-256 hash of the activation token.
 * @property \Illuminate\Support\Carbon|null $terms_accepted_at
 * @property \Illuminate\Support\Carbon|null $privacy_policy_accepted_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
class PendingRegistration extends Model
{
    use MassPrunable;

    protected $table = 'pending_registrations';

    protected $fillable = [
        'name',
        'email',
        'date_of_birth',
        'gender',
        'registration_role',
        'token',
        'terms_accepted_at',
        'privacy_policy_accepted_at',
        'expires_at',
        'group_ids',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'terms_accepted_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
            'expires_at' => 'datetime',
            'group_ids' => 'array',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->token, static::hashToken($token));
    }

    /**
     * Registrations whose activation link has expired.
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
