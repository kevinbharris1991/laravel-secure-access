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
     * This method ONLY seeds entirely empty databases (no groups and no permissions).
     * On subsequent runs or in existing installations, it returns immediately without
     * making changes. The database is the authoritative source once initialized.
     *
     * @throws LogicException
     */
    public function seed(): void
    {
        if (! config('secure-access-acl.enabled')) {
            throw new LogicException('Enable secure-access-acl before seeding the camp preset.');
        }

        DB::transaction(function () {
            if (AccessGroup::exists() || Permission::exists()) {
                return;
            }

            foreach (CampDefaults::groups() as $key => $definition) {
                $group = AccessGroup::create(['key' => $key, 'name' => $definition['name']]);

                foreach ($definition['permissions'] as $permissionKey) {
                    $scope = match (true) {
                        str_ends_with($permissionKey, '_own') => 'own',
                        str_ends_with($permissionKey, '_assigned') => 'assigned',
                        default => 'all',
                    };

                    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['scope' => $scope]);

                    $group->permissions()->attach($permission->id);
                }
            }
        });
    }
}
