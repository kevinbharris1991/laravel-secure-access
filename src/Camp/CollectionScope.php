<?php

namespace SecureAccess\Camp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\Authorization\AccessScope;

final class CollectionScope implements AccessScope
{
    public function __construct(private readonly ?Authenticatable $actor)
    {
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return $this->actor instanceof Model && $user instanceof Model
            && $this->actor->exists && $user->exists
            && $this->actor->getMorphClass() === $user->getMorphClass()
            && (string) $this->actor->getKey() === (string) $user->getKey();
    }

    public function isAssignedTo(Authenticatable $user): bool
    {
        // Collection authorization permits only a subsequently assignment-filtered query.
        return $this->isOwnedBy($user);
    }
}
