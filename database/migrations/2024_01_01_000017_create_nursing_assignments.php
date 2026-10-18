<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secure_access_team_members', function (Blueprint $table) {
            $table->string('nurse_assigned_by')->nullable()->index();
            $table->timestamp('nurse_assigned_at')->nullable();
        });

        Schema::create('secure_access_nursing_assignment_audits', function (Blueprint $table) {
            $table->id();
            $table->string('actor_id');
            $table->unsignedBigInteger('team_member_id')->index();
            $table->string('action');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_nursing_assignment_audits');
        Schema::table('secure_access_team_members', function (Blueprint $table) {
            $table->dropIndex(['nurse_assigned_by']);
            $table->dropColumn(['nurse_assigned_by', 'nurse_assigned_at']);
        });
    }
};
