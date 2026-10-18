<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secure_access_team_authority_documents', function (Blueprint $table) {
            $table->string('review_decision')->default('pending')->index();
            $table->string('lifecycle_status')->default('active')->index();
        });

        foreach (['approved' => 'confirmed', 'rejected' => 'denied', 'needs_information' => 'inquired'] as $status => $decision) {
            DB::table('secure_access_team_authority_documents')->where('status', $status)
                ->update(['review_decision' => $decision]);
        }
        foreach (['inactive', 'superseded', 'suspended', 'revoked', 'expired'] as $status) {
            DB::table('secure_access_team_authority_documents')->where('status', $status)
                ->update(['lifecycle_status' => $status]);
        }
        // Historical disabled documents remain unconfirmed: a legacy lifecycle value is not proof of review.
    }

    public function down(): void
    {
        Schema::table('secure_access_team_authority_documents', function (Blueprint $table) {
            $table->dropIndex(['review_decision']);
            $table->dropIndex(['lifecycle_status']);
            $table->dropColumn(['review_decision', 'lifecycle_status']);
        });
    }
};
