<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_access_team_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('self_advocate_id')->index();
            $table->timestamps();
        });

        Schema::create('secure_access_conversation_participants', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('secure_access_team_conversations')->cascadeOnDelete();
            $table->string('user_id');
            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('secure_access_team_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('secure_access_team_conversations')->cascadeOnDelete();
            $table->string('sender_id');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('secure_access_team_message_reads', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained('secure_access_team_messages')->cascadeOnDelete();
            $table->string('user_id');
            $table->timestamp('read_at');
            $table->primary(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_access_team_message_reads');
        Schema::dropIfExists('secure_access_team_messages');
        Schema::dropIfExists('secure_access_conversation_participants');
        Schema::dropIfExists('secure_access_team_conversations');
    }
};
