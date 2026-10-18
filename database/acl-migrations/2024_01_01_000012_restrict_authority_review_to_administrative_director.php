<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('secure_access_permissions')->where('key', 'authority_documents.review_all')->value('id');
        if (! $permissionId) {
            return;
        }
        $directorId = DB::table('secure_access_groups')->where('key', 'administrative-director')->value('id');
        DB::table('secure_access_group_grants')->where('permission_id', $permissionId)
            ->when($directorId, fn ($query) => $query->where('group_id', '!=', $directorId))->delete();
    }

    public function down(): void
    {
        // Do not restore broad review access on rollback.
    }
};
