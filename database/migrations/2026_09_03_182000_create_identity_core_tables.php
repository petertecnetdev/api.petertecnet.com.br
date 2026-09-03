<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('platform', 120)->nullable();
            $table->string('browser', 120)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->boolean('trusted')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'uuid']);
            $table->index(['user_id', 'last_seen_at']);
        });

        Schema::create('identity_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('identity_devices')->nullOnDelete();
            $table->foreignId('last_application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->unsignedInteger('auth_version')->default(1);
            $table->char('session_token_hash', 64)->unique();
            $table->char('refresh_token_hash', 64)->unique();
            $table->char('previous_refresh_token_hash', 64)->nullable()->index();
            $table->timestamp('previous_refresh_valid_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'expires_at']);
            $table->index(['device_id', 'revoked_at']);
        });

        Schema::create('identity_auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('identity_session_id')->nullable();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('outcome', 32)->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['identity_session_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('identity_passkey_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('credential_id_hash', 64)->unique();
            $table->text('credential_id');
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->string('label', 160)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_passkey_credentials');
        Schema::dropIfExists('identity_auth_events');
        Schema::dropIfExists('identity_sessions');
        Schema::dropIfExists('identity_devices');
    }
};
