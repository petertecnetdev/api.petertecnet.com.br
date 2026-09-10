<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('reason', 500);
            $table->char('handoff_token_hash', 64)->nullable()->unique();
            $table->timestamp('handoff_expires_at')->nullable();
            $table->timestamp('handoff_used_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['impersonator_user_id', 'ended_at', 'expires_at'], 'imp_sessions_actor_active_idx');
            $table->index(['impersonated_user_id', 'ended_at', 'expires_at'], 'imp_sessions_target_active_idx');
            $table->index(['application_id', 'created_at'], 'imp_sessions_app_created_idx');
        });

        Schema::create('impersonation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_session_id')->constrained('impersonation_sessions')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('effective_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('route_name', 190)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('action', 120)->nullable();
            $table->string('entity_type', 190)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['impersonation_session_id', 'created_at'], 'imp_audit_session_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'imp_audit_actor_created_idx');
            $table->index(['effective_user_id', 'created_at'], 'imp_audit_effective_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_audit_logs');
        Schema::dropIfExists('impersonation_sessions');
    }
};
