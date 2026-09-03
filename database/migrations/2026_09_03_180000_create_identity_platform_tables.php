<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('auth_method', 40)->default('password')->index();
            $table->string('device_label', 180)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoke_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['app_id', 'last_seen_at']);
        });

        Schema::create('identity_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 60)->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['purpose', 'user_id', 'expires_at']);
        });

        Schema::create('identity_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40)->default('passkey')->index();
            $table->string('credential_id', 700)->unique();
            $table->text('public_key');
            $table->integer('algorithm')->default(-7);
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->string('name', 180)->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::create('identity_security_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('two_factor_enabled')->default(false)->index();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_security_settings');
        Schema::dropIfExists('identity_credentials');
        Schema::dropIfExists('identity_challenges');
        Schema::dropIfExists('identity_sessions');
    }
};
