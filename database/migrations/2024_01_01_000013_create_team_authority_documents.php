<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_team_authority_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_member_id')->constrained('secure_access_team_members')->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('file_name');
            $table->timestamps();
            $table->index(['team_member_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_team_authority_documents');
    }
};
