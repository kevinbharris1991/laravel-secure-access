<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('type', 32);
            $table->string('name');
            // Encrypted TOTP secret or SMS phone number.
            $table->text('secret')->nullable();
            // WebAuthn credential data.
            $table->text('credential_id')->nullable();
            $table->char('credential_hash', 64)->nullable()->unique();
            $table->text('public_key')->nullable();
            $table->json('transports')->nullable();
            // WebAuthn signature counter or last accepted TOTP time step (replay protection).
            $table->unsignedBigInteger('counter')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_methods');
    }
};
