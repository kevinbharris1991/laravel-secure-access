<?php

namespace SecureAccess\Authorization;

use Illuminate\Support\Facades\DB;
use LogicException;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;

class CampPreset
{
    /**
     * Seed the database with default camp groups and permissions.
     *
     * This is idempotent: it will not overwrite existing groups or permissions,
     * and it will not restore permissions that have been removed from a group.
     * The database is the authoritative source once seeded.
     *
     * @throws LogicException
     */
    public function seed(): void
    {
        if (! config('secure-access-acl.enabled')) {
            throw new LogicException('Enable secure-access-acl before seeding the camp preset.');
        }

        DB::transaction(function () {
            foreach (config('secure-access-camp.groups', []) as $key => $definition) {
                // Get or create the group (without overwriting)
                $group = AccessGroup::firstOrCreate(['key' => $key], ['name' => $definition['name']]);

                // For each permission in the definition, ensure it exists and grant if missing
                foreach ($definition['permissions'] as $permissionKey) {
                    $scope = match (true) {
                        str_ends_with($permissionKey, '_own') => 'own',
                        str_ends_with($permissionKey, '_assigned') => 'assigned',
                        default => 'all',
                    };

                    // Create permission if it doesn't exist
                    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['scope' => $scope]);

                    // Validate that the scope hasn't changed (would indicate a configuration conflict)
                    if ($permission->scope !== $scope) {
                        throw new LogicException(
                            "Conflicting scope for camp permission: {$permissionKey} ".
                            "(existing: {$permission->scope}, requested: {$scope})"
                        );
                    }

                    // Grant permission to group if not already granted (idempotent)
                    // Use syncWithoutDetaching to avoid removing manually-added permissions
                    $group->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        });
    }
}
