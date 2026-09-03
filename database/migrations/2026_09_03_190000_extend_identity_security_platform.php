<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('device_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('secret_hash', 64)->unique();
            $table->string('name', 180)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoke_reason', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });

        Schema::create('identity_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('fingerprint', 64)->index();
            $table->text('value_encrypted')->nullable();
            $table->string('display_hint', 180)->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['type', 'fingerprint']);
            $table->index(['user_id', 'type', 'verified_at']);
        });

        Schema::create('identity_step_up_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('grant_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('identity_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('action', 120)->index();
            $table->string('method', 40)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'action', 'expires_at']);
        });

        Schema::table('identity_sessions', function (Blueprint $table) {
            $table->string('nickname', 120)->nullable()->after('device_label');
            $table->unsignedSmallInteger('risk_score')->default(0)->after('user_agent')->index();
            $table->json('risk_reasons')->nullable()->after('risk_score');
            $table->foreignId('trusted_device_id')->nullable()->after('risk_reasons')->constrained('identity_trusted_devices')->nullOnDelete();
            $table->timestamp('idle_expires_at')->nullable()->after('last_seen_at')->index();
            $table->timestamp('absolute_expires_at')->nullable()->after('expires_at')->index();
        });

        Schema::table('identity_global_sessions', function (Blueprint $table) {
            $table->foreignId('trusted_device_id')->nullable()->after('user_id')->constrained('identity_trusted_devices')->nullOnDelete();
            $table->json('risk_reasons')->nullable()->after('risk_score');
            $table->string('country_code', 8)->nullable()->after('ip_address');
            $table->timestamp('idle_expires_at')->nullable()->after('last_seen_at')->index();
            $table->timestamp('absolute_expires_at')->nullable()->after('expires_at')->index();
        });

        Schema::table('identity_security_settings', function (Blueprint $table) {
            $table->timestamp('recovery_codes_generated_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('last_recovery_code_used_at')->nullable()->after('recovery_codes_generated_at');
            $table->timestamp('last_step_up_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('identity_security_settings', function (Blueprint $table) {
            $table->dropColumn(['recovery_codes_generated_at', 'last_recovery_code_used_at', 'last_step_up_at']);
        });
        Schema::table('identity_global_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_device_id');
            $table->dropColumn(['risk_reasons', 'country_code', 'idle_expires_at', 'absolute_expires_at']);
        });
        Schema::table('identity_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_device_id');
            $table->dropColumn(['nickname', 'risk_score', 'risk_reasons', 'idle_expires_at', 'absolute_expires_at']);
        });
        Schema::dropIfExists('identity_step_up_grants');
        Schema::dropIfExists('identity_identifiers');
        Schema::dropIfExists('identity_trusted_devices');
    }
};
