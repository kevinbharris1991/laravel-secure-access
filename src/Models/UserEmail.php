<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SecureAccess\SecureAccess;

/**
 * @property int $id
 * @property int|string $user_id
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class UserEmail extends Model
{
    protected $table = 'user_emails';

    protected $fillable = [
        'user_id',
        'email',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SecureAccess::userModel(), 'user_id');
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Hash embedded in the verification link.
     */
    public function verificationHash(): string
    {
        return sha1($this->email);
    }
}
