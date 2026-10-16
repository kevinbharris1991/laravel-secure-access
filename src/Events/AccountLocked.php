<?php

namespace SecureAccess\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class AccountLocked
{
    use Dispatchable;

    public function __construct(public Model $user)
    {
    }
}
