<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationInvitation extends Model
{
    protected $table = 'secure_access_invitations';

    protected $fillable = ['email', 'token', 'group_ids', 'expires_at', 'accepted_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'group_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function usableFor(string $email): bool
    {
        return $this->accepted_at === null
            && $this->expires_at->isFuture()
            && $this->email === mb_strtolower(trim($email));
    }
}
