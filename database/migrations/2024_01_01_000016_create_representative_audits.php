<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_representative_audits', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('represented_id');
            $table->unsignedBigInteger('document_id');
            $table->string('module');
            $table->string('action');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_representative_audits');
    }
};
