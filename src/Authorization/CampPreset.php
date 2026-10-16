<?php

namespace SecureAccess\Authorization;

use Illuminate\Support\Facades\DB;
use LogicException;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\Permission;

class CampPreset
{
    public function seed(): void
    {
        if (! config('secure-access-acl.enabled')) {
            throw new LogicException('Enable secure-access-acl before seeding the camp preset.');
        }

        DB::transaction(function () {
            foreach (config('secure-access-camp.groups', []) as $key => $definition) {
                $group = AccessGroup::firstOrCreate(['key' => $key], ['name' => $definition['name']]);

                foreach ($definition['permissions'] as $permissionKey) {
                    $scope = match (true) {
                        str_ends_with($permissionKey, '_own') => 'own',
                        str_ends_with($permissionKey, '_assigned') => 'assigned',
                        default => 'all',
                    };
                    $permission = Permission::firstOrCreate(['key' => $permissionKey], ['scope' => $scope]);

                    if ($permission->scope !== $scope) {
                        throw new LogicException('Conflicting scope for camp permission: '.$permissionKey);
                    }

                    $group->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        });
    }
}
