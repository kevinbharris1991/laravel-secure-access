<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_financial_application_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('ward_id')->index();
            $table->foreignId('document_id')->constrained('secure_access_team_authority_documents', 'id', 'safad_document_fk');
            $table->foreignId('share_id')->constrained('secure_access_authority_department_shares', 'id', 'safad_share_fk');
            $table->foreignId('assignment_id')->constrained('secure_access_department_assignments', 'id', 'safad_assignment_fk');
            $table->unsignedInteger('assignment_version');
            $table->unsignedInteger('share_revision');
            $table->string('document_signature', 64);
            $table->string('action');
            $table->string('target');
            $table->text('basis');
            $table->text('reason');
            $table->string('reviewer_id');
            $table->timestamp('reviewed_at');
            $table->date('effective_at')->index();
        });

        Schema::create('secure_access_financial_decision_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->unique()->constrained('secure_access_financial_application_decisions', 'id', 'safdd_decision_fk');
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_financial_decision_deliveries');
        Schema::dropIfExists('secure_access_financial_application_decisions');
    }
};
