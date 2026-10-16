<?php

namespace SecureAccess\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SecureAccess\Models\Permission;

class AccessControl
{
    public function allows(?Authenticatable $user, string $permission, ?AccessScope $subject = null): bool
    {
        if (! config('secure-access-acl.enabled') || ! $user instanceof Model || ! $user->exists) {
            return false;
        }

        $grant = Permission::query()
            ->where('key', $permission)
            ->whereHas('groups', fn ($groups) => $groups->whereExists(
                fn ($memberships) => $memberships->selectRaw('1')
                    ->from('secure_access_memberships')
                    ->whereColumn('secure_access_memberships.group_id', 'secure_access_groups.id')
                    ->where('member_type', $user->getMorphClass())
                    ->where('member_id', (string) $user->getKey())
            ))
            ->first();

        return match ($grant?->scope) {
            'all' => true,
            'own' => $subject?->isOwnedBy($user) ?? false,
            'assigned' => $subject?->isAssignedTo($user) ?? false,
            default => false,
        };
    }
}
