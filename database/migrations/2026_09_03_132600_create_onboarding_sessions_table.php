<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->string('context', 100)->default('commercial_onboarding');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('status', 30)->default('in_progress');
            $table->json('state')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'status', 'last_activity_at'], 'onboarding_actor_status_idx');
            $table->index(['subject_user_id', 'status'], 'onboarding_subject_status_idx');
            $table->index(['context', 'status'], 'onboarding_context_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sessions');
    }
};
