<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable();
            }

            if (! Schema::hasColumn('users', 'gender')) {
                $table->string('gender')->nullable();
            }

            if (! Schema::hasColumn('users', 'profile_photo_path')) {
                $table->string('profile_photo_path', 2048)->nullable();
            }

            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
                $table->timestamp('privacy_policy_accepted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // The columns are intentionally kept: any of them may have existed before this migration ran.
    }
};
