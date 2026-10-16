<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->json('group_ids');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('invitation_id')->nullable()->index();
        });

        Schema::create('secure_access_registration_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('claimed')->default(false);
        });
        DB::table('secure_access_registration_state')->insert(['id' => 1, 'claimed' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_registration_state');
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('invitation_id');
        });
        Schema::dropIfExists('secure_access_invitations');
    }
};
