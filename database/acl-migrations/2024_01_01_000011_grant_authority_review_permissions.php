<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'executive-director' => ['authority_documents.review_all'],
            'administrative-director' => ['authority_documents.review_all'],
            'medical-nursing' => ['authority_documents.healthcare.read_all'],
            'finance-accounting' => ['authority_documents.financial.read_all'],
        ];

        foreach ($grants as $groupKey => $permissionKeys) {
            $groupId = DB::table('secure_access_groups')->where('key', $groupKey)->value('id');
            if (! $groupId) {
                continue;
            }
            foreach ($permissionKeys as $key) {
                $permission = DB::table('secure_access_permissions')->where('key', $key)->first();
                if (! $permission) {
                    DB::table('secure_access_permissions')->insert([
                        'key' => $key, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $permission = DB::table('secure_access_permissions')->where('key', $key)->first();
                }
                if ($permission->scope === 'all') {
                    DB::table('secure_access_group_grants')->insertOrIgnore([
                        'group_id' => $groupId, 'permission_id' => $permission->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Retain access grants so rolling back the package does not remove user-managed ACL data.
    }
};
