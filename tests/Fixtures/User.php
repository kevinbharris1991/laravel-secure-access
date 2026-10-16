<?php

namespace SecureAccess\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use SecureAccess\Concerns\HasSecureAccess;

class User extends Authenticatable
{
    use HasSecureAccess;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
