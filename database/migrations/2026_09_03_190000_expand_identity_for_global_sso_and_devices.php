<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_devices')) {
            Schema::create('identity_devices', function (Blueprint $table) {
                $table->id();
                $table->uuid('device_id')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('last_application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('name', 180)->nullable();
                $table->string('platform', 80)->nullable();
                $table->string('browser', 80)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('first_ip_address', 64)->nullable();
                $table->string('last_ip_address', 64)->nullable();
                $table->boolean('trusted')->default(false)->index();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('trusted_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'last_seen_at']);
            });
        }

        if (Schema::hasTable('identity_sessions') && ! Schema::hasColumn('identity_sessions', 'device_id')) {
            Schema::table('identity_sessions', function (Blueprint $table) {
                $table->foreignId('device_id')->nullable()->after('app_id')->constrained('identity_devices')->nullOnDelete();
                $table->index(['user_id', 'device_id']);
            });
        }

        if (! Schema::hasTable('identity_global_sessions')) {
            Schema::create('identity_global_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('session_id')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('identity_devices')->nullOnDelete();
                $table->string('session_token_hash', 64)->unique();
                $table->string('refresh_token_hash', 64)->unique();
                $table->string('previous_refresh_token_hash', 64)->nullable()->index();
                $table->timestamp('previous_refresh_valid_until')->nullable();
                $table->unsignedInteger('auth_version')->default(1);
                $table->string('device_label', 180)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('expires_at')->index();
                $table->timestamp('refresh_expires_at')->index();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->string('revoke_reason', 120)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'revoked_at']);
                $table->index(['device_id', 'last_seen_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_global_sessions');

        if (Schema::hasTable('identity_sessions') && Schema::hasColumn('identity_sessions', 'device_id')) {
            Schema::table('identity_sessions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('device_id');
            });
        }

        Schema::dropIfExists('identity_devices');
    }
};
