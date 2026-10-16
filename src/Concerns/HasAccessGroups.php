<?php

namespace SecureAccess\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Authorization\AccessScope;
use SecureAccess\Models\AccessGroup;

trait HasAccessGroups
{
    public static function bootHasAccessGroups(): void
    {
        static::deleted(function ($user) {
            if (config('secure-access-acl.enabled') && (! method_exists($user, 'isForceDeleting') || $user->isForceDeleting())) {
                $user->accessGroups()->detach();
            }
        });
    }

    public function accessGroups(): MorphToMany
    {
        return $this->morphToMany(AccessGroup::class, 'member', 'secure_access_memberships', 'member_id', 'group_id');
    }

    public function hasAccessPermission(string $permission, ?AccessScope $subject = null): bool
    {
        return app(AccessControl::class)->allows($this, $permission, $subject);
    }
}
