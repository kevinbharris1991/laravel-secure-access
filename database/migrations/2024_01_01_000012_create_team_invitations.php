<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_team_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('inviter_id');
            $table->string('invitee_email');
            $table->string('invitee_id')->nullable();
            $table->string('inviter_role');
            $table->string('invitee_role');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->index(['inviter_id', 'status']);
            $table->index(['invitee_id', 'status']);
            $table->index(['invitee_email', 'status']);
        });

        Schema::create('secure_access_team_members', function (Blueprint $table) {
            $table->id();
            $table->string('self_advocate_id');
            $table->string('team_member_id');
            $table->string('team_member_role');
            $table->timestamps();
            $table->unique(['self_advocate_id', 'team_member_id']);
            $table->index(['team_member_id', 'team_member_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_team_members');
        Schema::dropIfExists('secure_access_team_invitations');
    }
};
