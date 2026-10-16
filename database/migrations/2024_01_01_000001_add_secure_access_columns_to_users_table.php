<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $username = config('secure-access.username_column', 'username');

        Schema::table('users', function (Blueprint $table) use ($username) {
            if (! Schema::hasColumn('users', $username)) {
                $table->string($username)->nullable()->unique();
            }

            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
        });
    }

    public function down(): void
    {
        // The username column is intentionally kept: it may have existed before this migration ran.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_at']);
        });
    }
};
