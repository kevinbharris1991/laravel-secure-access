<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use SecureAccess\Authorization\CampPreset;

return new class extends Migration
{
    public function up(): void
    {
        // Initialize default camp groups and permissions (only if database is empty)
        (new CampPreset())->seed();

        // Upgrade administrative-directors (plural) to administrative-director (singular)
        $this->upgradePluralGroupKey();

        foreach ([
            'executive-director' => 'Executive Director',
            'administrative-director' => 'Administrative Director',
            'guest' => 'Guest',
        ] as $key => $name) {
            DB::table('secure_access_groups')->insertOrIgnore([
                'key' => $key,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Grant new bootstrap permissions to existing groups (for existing installs)
        $this->grantBootstrapPermissions();
    }

    public function down(): void
    {
        // No destructive cleanup—retain all initialized data
    }

    /**
     * Upgrade administrative-directors (plural) to administrative-director (singular).
     * Preserves all grants and memberships, merging data if singular already exists.
     */
    private function upgradePluralGroupKey(): void
    {
        DB::transaction(function () {
            $pluralGroup = DB::table('secure_access_groups')
                ->where('key', 'administrative-directors')
                ->first();

            if (! $pluralGroup) {
                return;
            }

            $singularKey = 'administrative-director';
            $singularGroup = DB::table('secure_access_groups')
                ->where('key', $singularKey)
                ->first();

            if ($singularGroup) {
                // Merge: move all grants and memberships from plural to singular
                $grants = DB::table('secure_access_group_grants')
                    ->where('group_id', $pluralGroup->id)
                    ->get();

                foreach ($grants as $grant) {
                    DB::table('secure_access_group_grants')->insertOrIgnore([
                        'group_id' => $singularGroup->id,
                        'permission_id' => $grant->permission_id,
                    ]);
                }

                // Memberships
                $memberships = DB::table('secure_access_memberships')
                    ->where('group_id', $pluralGroup->id)
                    ->get();

                foreach ($memberships as $membership) {
                    DB::table('secure_access_memberships')->insertOrIgnore([
                        'group_id' => $singularGroup->id,
                        'member_type' => $membership->member_type,
                        'member_id' => $membership->member_id,
                    ]);
                }

                // Delete plural group
                DB::table('secure_access_groups')->where('id', $pluralGroup->id)->delete();
            } else {
                // Rename: plural becomes singular
                DB::table('secure_access_groups')
                    ->where('id', $pluralGroup->id)
                    ->update(['key' => $singularKey]);
            }
        });
    }

    /**
     * Grant new bootstrap permissions to existing groups.
     * For existing installs: adds invitations.create_all to administrative-director
     * and executive-director, plus groups.manage_all and permissions.manage_all to
     * executive-director only. Preserves all existing grants.
     *
     * If a permission already exists with 'own' or 'assigned' scope, it is not
     * broadened to 'all'—the grant is not added (permission remains scoped).
     */
    private function grantBootstrapPermissions(): void
    {
        DB::transaction(function () {
            $bootstrapGrants = [
                'executive-director' => [
                    'invitations.create_all',
                    'groups.manage_all',
                    'permissions.manage_all',
                ],
                'administrative-director' => [
                    'invitations.create_all',
                ],
            ];

            foreach ($bootstrapGrants as $groupKey => $permissionKeys) {
                $group = DB::table('secure_access_groups')
                    ->where('key', $groupKey)
                    ->first();

                if (! $group) {
                    continue; // Group doesn't exist (e.g., new install already seeded)
                }

                foreach ($permissionKeys as $permissionKey) {
                    // Get or create permission with 'all' scope
                    $permission = DB::table('secure_access_permissions')
                        ->where('key', $permissionKey)
                        ->first();

                    if (! $permission) {
                        // Create new permission with 'all' scope
                        DB::table('secure_access_permissions')->insert([
                            'key' => $permissionKey,
                            'scope' => 'all',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $permission = DB::table('secure_access_permissions')
                            ->where('key', $permissionKey)
                            ->first();
                    } elseif ($permission->scope !== 'all') {
                        // Permission exists with different scope (own/assigned)
                        // Do not broaden its scope—skip the grant
                        continue;
                    }

                    // Grant permission to group if not already granted
                    DB::table('secure_access_group_grants')->insertOrIgnore([
                        'group_id' => $group->id,
                        'permission_id' => $permission->id,
                    ]);
                }
            }
        });
    }
};
