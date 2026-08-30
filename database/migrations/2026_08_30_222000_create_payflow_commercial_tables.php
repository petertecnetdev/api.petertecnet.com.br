<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payflow_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('payflow_contacts')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('stage', 40)->default('new');
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(20);
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'establishment_id', 'stage'], 'pf_opp_app_est_stage_idx');
        });

        Schema::create('payflow_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('payflow_opportunities')->nullOnDelete();
            $table->foreignId('contact_id')->constrained('payflow_contacts')->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('status', 30)->default('draft');
            $table->json('items');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'establishment_id', 'status'], 'pf_prop_app_est_status_idx');
        });

        Schema::create('payflow_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('payflow_contacts')->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained('payflow_proposals')->nullOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('pix_copy_paste', 1024)->nullable();
            $table->text('qr_code_payload')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'establishment_id', 'status'], 'pf_charge_app_est_status_idx');
            $table->index(['provider', 'external_id'], 'pf_charge_provider_external_idx');
        });

        Schema::create('payflow_agent_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('payflow_contacts')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('payflow_opportunities')->nullOnDelete();
            $table->string('type', 50);
            $table->string('channel', 30)->default('system');
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();
            $table->index(['app_id', 'establishment_id', 'executed_at'], 'pf_activity_app_est_exec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payflow_agent_activities');
        Schema::dropIfExists('payflow_charges');
        Schema::dropIfExists('payflow_proposals');
        Schema::dropIfExists('payflow_opportunities');
    }
};
