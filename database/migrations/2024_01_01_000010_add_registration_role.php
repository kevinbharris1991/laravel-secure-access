<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pending_registrations', 'registration_role')) {
            Schema::table('pending_registrations', function (Blueprint $table) {
                $table->string('registration_role')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'registration_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('registration_role')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pending_registrations', 'registration_role')) {
            Schema::table('pending_registrations', function (Blueprint $table) {
                $table->dropColumn('registration_role');
            });
        }
    }
};
