<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_camp_records', function (Blueprint $table) {
            $table->id();
            $table->string('module', 80)->index();
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->unsignedBigInteger('camper_id')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unique(['module', 'source_id'], 'camp_projection_source');
            $table->boolean('is_public')->default(false);
            $table->longText('data');
            $table->timestamps();
            $table->index(['module', 'owner_type', 'owner_id'], 'camp_record_owner');
        });
        Schema::create('secure_access_camp_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('user_type');
            $table->string('user_id');
            $table->foreignId('camper_id')->constrained('secure_access_camp_records')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_type', 'user_id', 'camper_id'], 'camp_assignment_unique');
        });
        Schema::create('secure_access_camp_audits', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('action', 40);
            $table->string('module', 80)->index();
            $table->string('record_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_camp_audits');
        Schema::dropIfExists('secure_access_camp_assignments');
        Schema::dropIfExists('secure_access_camp_records');
    }
};
