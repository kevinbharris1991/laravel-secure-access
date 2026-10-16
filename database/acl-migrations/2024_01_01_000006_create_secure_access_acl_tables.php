<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('scope')->default('all');
            $table->timestamps();
        });

        Schema::create('secure_access_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('secure_access_group_grants', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('secure_access_groups')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('secure_access_permissions')->cascadeOnDelete();
            $table->primary(['group_id', 'permission_id']);
        });

        Schema::create('secure_access_memberships', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('secure_access_groups')->cascadeOnDelete();
            $table->string('member_type');
            $table->string('member_id', 64);
            $table->unique(['group_id', 'member_type', 'member_id'], 'secure_access_membership_unique');
            $table->index(['member_type', 'member_id'], 'secure_access_membership_member');
        });

        // Initialize default camp groups and permissions from config
        $this->seedDefaultGroups();
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_memberships');
        Schema::dropIfExists('secure_access_group_grants');
        Schema::dropIfExists('secure_access_groups');
        Schema::dropIfExists('secure_access_permissions');
    }

    /**
     * Seed default camp groups and permissions from configuration.
     * This ensures ACL is available immediately after migration.
     */
    private function seedDefaultGroups(): void
    {
        $groupsConfig = config('secure-access-camp.groups', []);

        DB::transaction(function () use ($groupsConfig) {
            foreach ($groupsConfig as $key => $definition) {
                $group = DB::table('secure_access_groups')->insertOrIgnore([
                    'key' => $key,
                    'name' => $definition['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Get the group ID (insertOrIgnore doesn't return it, so query)
                $groupId = DB::table('secure_access_groups')->where('key', $key)->value('id');

                foreach ($definition['permissions'] as $permissionKey) {
                    $scope = match (true) {
                        str_ends_with($permissionKey, '_own') => 'own',
                        str_ends_with($permissionKey, '_assigned') => 'assigned',
                        default => 'all',
                    };

                    $permissionId = DB::table('secure_access_permissions')
                        ->insertOrIgnore([
                            'key' => $permissionKey,
                            'scope' => $scope,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    // Get the permission ID
                    $permissionId = DB::table('secure_access_permissions')
                        ->where('key', $permissionKey)
                        ->value('id');

                    // Grant permission to group if not already granted
                    DB::table('secure_access_group_grants')->insertOrIgnore([
                        'group_id' => $groupId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        });
    }
};
