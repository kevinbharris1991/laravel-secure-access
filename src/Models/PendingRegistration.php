<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\SecureAccess;

class PendingRegistration extends Model
{
    use HasUuids;

    protected $table = 'secure_access_registrations';

    protected $fillable = ['name', 'date_of_birth', 'email', 'gender', 'expires_at'];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'expires_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return SecureAccess::newUserQuery()->getModel()->getConnectionName();
    }
}
