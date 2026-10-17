<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registration_roles', function (Blueprint $table) {
            $table->string('user_id')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registration_roles');
    }
};
