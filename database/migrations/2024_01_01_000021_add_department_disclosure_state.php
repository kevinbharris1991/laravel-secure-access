<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secure_access_authority_department_shares', function (Blueprint $table) {
            // Legacy copies remain private drafts until explicitly reviewed and published.
            $table->string('status')->default('draft')->index();
            $table->date('effective_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('redacted_verified_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::create('secure_access_department_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('ward_id');
            $table->string('department');
            $table->string('assignee_id')->nullable();
            $table->string('assigned_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['ward_id', 'department'], 'sada_ward_department_unique');
        });

        Schema::create('secure_access_department_assignment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('secure_access_department_assignments', 'id', 'sadaa_assignment_fk');
            $table->string('actor_id');
            $table->string('previous_assignee_id')->nullable();
            $table->string('assignee_id')->nullable();
            $table->string('action');
            $table->timestamp('created_at');
        });

        Schema::create('secure_access_department_disclosure_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained('secure_access_authority_department_shares', 'id', 'saddg_share_fk');
            $table->foreignId('assignment_id')->constrained('secure_access_department_assignments', 'id', 'saddg_assignment_fk');
            $table->unsignedInteger('assignment_version');
            $table->unsignedInteger('share_revision');
            $table->string('recipient_id');
            $table->boolean('active')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['share_id', 'assignment_id', 'assignment_version', 'share_revision'], 'saddg_delivery_unique');
        });

        // Preserve an unambiguous existing nursing assignment; never guess between multiple staff.
        $wards = DB::table('secure_access_team_members')->whereNull('ended_at')->whereNotNull('nurse_assigned_by')
            ->select('self_advocate_id')->groupBy('self_advocate_id')->havingRaw('count(*) = 1')->pluck('self_advocate_id');
        foreach ($wards as $wardId) {
            $relationship = DB::table('secure_access_team_members')->where('self_advocate_id', $wardId)
                ->whereNull('ended_at')->whereNotNull('nurse_assigned_by')->first();
            $id = DB::table('secure_access_department_assignments')->insertGetId([
                'ward_id' => $wardId, 'department' => 'medical-nursing', 'assignee_id' => $relationship->team_member_id,
                'assigned_by' => $relationship->nurse_assigned_by, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('secure_access_department_assignment_audits')->insert([
                'assignment_id' => $id, 'actor_id' => $relationship->nurse_assigned_by, 'previous_assignee_id' => null,
                'assignee_id' => $relationship->team_member_id, 'action' => 'migrated', 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_department_disclosure_grants');
        Schema::dropIfExists('secure_access_department_assignment_audits');
        Schema::dropIfExists('secure_access_department_assignments');
        Schema::table('secure_access_authority_department_shares', fn (Blueprint $table) =>
            $table->dropColumn(['status', 'effective_at', 'expires_at', 'published_at', 'redacted_verified_at', 'revision']));
    }
};
