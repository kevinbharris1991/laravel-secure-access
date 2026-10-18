<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secure_access_team_members', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable();
        });
        Schema::table('secure_access_team_authority_documents', function (Blueprint $table) {
            $table->string('status')->default('pending')->index();
            $table->string('submitted_by')->nullable();
            $table->json('approved_scopes')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('previous_document_id')->nullable()
                ->constrained('secure_access_team_authority_documents', 'id', 'satad_previous_fk');
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_history')->nullable();
        });
        Schema::create('secure_access_authority_document_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()
                ->constrained('secure_access_team_authority_documents', 'id', 'saada_document_fk');
            $table->foreignId('team_member_id')
                ->constrained('secure_access_team_members', 'id', 'saada_member_fk');
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_authority_document_audits');
        Schema::table('secure_access_team_authority_documents', function (Blueprint $table) {
            $table->dropForeign('satad_previous_fk');
            $table->dropColumn(['status', 'submitted_by', 'approved_scopes', 'effective_at', 'expires_at',
                'previous_document_id', 'reviewed_by', 'reviewed_at', 'review_history']);
        });
        Schema::table('secure_access_team_members', fn (Blueprint $table) => $table->dropColumn('ended_at'));
    }
};
