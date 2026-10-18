<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_authority_department_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('secure_access_team_authority_documents', 'id', 'saads_document_fk');
            $table->string('department');
            $table->string('created_by');
            $table->text('summary');
            $table->text('limitations');
            $table->string('path');
            $table->string('file_name');
            $table->timestamps();
            $table->index(['department', 'document_id'], 'saads_department_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_authority_department_shares');
    }
};
