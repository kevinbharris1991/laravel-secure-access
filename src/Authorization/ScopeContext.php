<?php

namespace SecureAccess\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;

class ScopeContext implements AccessScope
{
    public function __construct(
        public readonly int|string|null $ownerId = null,
        public readonly array $assignedUserIds = [],
    ) {
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return $this->ownerId !== null && (string) $this->ownerId === (string) $user->getAuthIdentifier();
    }

    public function isAssignedTo(Authenticatable $user): bool
    {
        foreach ($this->assignedUserIds as $id) {
            if ((is_int($id) || is_string($id)) && (string) $id === (string) $user->getAuthIdentifier()) {
                return true;
            }
        }

        return false;
    }
}
