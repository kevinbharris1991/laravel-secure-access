<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\Notifications\ActivateAccount;

/**
 * A registration whose email address has not been confirmed through the activation link yet.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon $date_of_birth
 * @property string $gender
 * @property string $token SHA-256 hash of the activation token.
 * @property \Illuminate\Support\Carbon $terms_accepted_at
 * @property \Illuminate\Support\Carbon $privacy_policy_accepted_at
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
        'token',
        'terms_accepted_at',
        'privacy_policy_accepted_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'terms_accepted_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
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
        return static::query()->where(
            'updated_at', '<', now()->subMinutes(ActivateAccount::expires())
        );
    }
}
