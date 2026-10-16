<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_memberships');
        Schema::dropIfExists('secure_access_group_grants');
        Schema::dropIfExists('secure_access_groups');
        Schema::dropIfExists('secure_access_permissions');
    }
};
