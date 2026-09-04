<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('production_id')->nullable()->constrained('productions')->nullOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('activation_code_hash');
            $table->boolean('requires_password')->default(true);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'agent_user_id', 'status'], 'acquisition_referrals_agent_status_idx');
            $table->index(['application_id', 'email'], 'acquisition_referrals_app_email_idx');
            $table->unique(['application_id', 'production_id'], 'acquisition_referrals_app_production_unique');
        });

        Schema::create('event_acquisition_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referral_id')->constrained('acquisition_referrals')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->decimal('percentage', 5, 2)->default(0);
            $table->string('basis', 32)->default('gross_sales');
            $table->timestamps();

            $table->unique(['application_id', 'event_id'], 'event_acquisition_commissions_app_event_unique');
            $table->index(['application_id', 'agent_user_id'], 'event_acquisition_commissions_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_acquisition_commissions');
        Schema::dropIfExists('acquisition_referrals');
    }
};
