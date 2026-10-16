<?php

namespace SecureAccess\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

interface AccessScope
{
    public function isOwnedBy(Authenticatable $user): bool;

    public function isAssignedTo(Authenticatable $user): bool;
}
