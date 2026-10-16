<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->json('group_ids')->nullable();
            $table->string('name')->nullable()->change();
            $table->date('date_of_birth')->nullable()->change();
            $table->string('gender')->nullable()->change();
            $table->timestamp('terms_accepted_at')->nullable()->change();
            $table->timestamp('privacy_policy_accepted_at')->nullable()->change();
            $table->dropIndex(['invitation_id']);
            $table->dropColumn('invitation_id');
        });

        foreach (DB::table('pending_registrations')->get(['id', 'updated_at']) as $registration) {
            DB::table('pending_registrations')->where('id', $registration->id)->update([
                'expires_at' => \Illuminate\Support\Carbon::parse($registration->updated_at)->addMinutes(5),
            ]);
        }

        Schema::dropIfExists('secure_access_invitations');
    }

    public function down(): void
    {
        DB::table('pending_registrations')->whereNull('name')->delete();

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
            $table->dropColumn(['expires_at', 'group_ids']);
            $table->string('name')->nullable(false)->change();
            $table->date('date_of_birth')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
            $table->timestamp('terms_accepted_at')->nullable(false)->change();
            $table->timestamp('privacy_policy_accepted_at')->nullable(false)->change();
        });
    }
};
