<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cognitive_agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('identity')->nullable();
            $table->text('purpose')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('constraints')->nullable();
            $table->json('values')->nullable();
            $table->json('self_model')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->boolean('learning_enabled')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cognitive_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->foreignId('interaction_id')->nullable()->constrained('interactions')->nullOnDelete();
            $table->string('source_channel', 64)->default('api')->index();
            $table->string('event_type', 100)->index();
            $table->string('entity_type', 120)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('external_key', 191)->nullable();
            $table->json('payload')->nullable();
            $table->decimal('salience', 5, 4)->default(0.2500)->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['agent_id', 'external_key'], 'cog_obs_agent_external_unique');
            $table->index(['agent_id', 'event_type', 'created_at'], 'cog_obs_agent_event_created_idx');
        });

        Schema::create('cognitive_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained('cognitive_observations')->nullOnDelete();
            $table->string('memory_type', 32)->default('episodic')->index();
            $table->string('title')->nullable();
            $table->text('summary');
            $table->json('content')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('importance', 5, 4)->default(0.5000)->index();
            $table->decimal('confidence', 5, 4)->default(0.5000)->index();
            $table->unsignedInteger('reinforcement_count')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_reinforced_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['agent_id', 'memory_type', 'active'], 'cog_mem_agent_type_active_idx');
        });

        Schema::create('cognitive_beliefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->string('subject', 100);
            $table->string('predicate', 100);
            $table->string('object_key', 191);
            $table->json('object_value')->nullable();
            $table->decimal('confidence', 5, 4)->default(0.5000)->index();
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('contradiction_count')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->text('source_summary')->nullable();
            $table->timestamp('last_evidence_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['agent_id', 'subject', 'predicate', 'object_key'], 'cognitive_belief_identity_unique');
        });

        Schema::create('cognitive_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 32)->default('system')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->string('status', 24)->default('active')->index();
            $table->json('success_criteria')->nullable();
            $table->json('context')->nullable();
            $table->decimal('progress', 5, 4)->default(0.0000);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status', 'priority'], 'cog_goal_agent_status_priority_idx');
        });

        Schema::create('cognitive_state_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->json('attention')->nullable();
            $table->json('self_model')->nullable();
            $table->json('world_model')->nullable();
            $table->json('working_memory')->nullable();
            $table->json('uncertainties')->nullable();
            $table->json('active_goals')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->index(['agent_id', 'captured_at'], 'cog_state_agent_captured_idx');
        });

        Schema::create('cognitive_experiments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name');
            $table->string('dimension', 100)->index();
            $table->text('hypothesis');
            $table->json('protocol');
            $table->json('pass_criteria');
            $table->decimal('weight', 5, 4)->default(1.0000);
            $table->boolean('enabled')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('cognitive_experiment_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained('cognitive_experiments')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input')->nullable();
            $table->json('evidence')->nullable();
            $table->json('metrics')->nullable();
            $table->decimal('score', 5, 4)->nullable()->index();
            $table->string('result', 24)->default('inconclusive')->index();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'experiment_id', 'created_at'], 'cog_exp_run_agent_exp_created_idx');
        });

        Schema::create('cognitive_learning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('cognitive_agents')->cascadeOnDelete();
            $table->foreignId('observation_id')->nullable()->constrained('cognitive_observations')->nullOnDelete();
            $table->foreignId('memory_id')->nullable()->constrained('cognitive_memories')->nullOnDelete();
            $table->foreignId('belief_id')->nullable()->constrained('cognitive_beliefs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64)->index();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->decimal('confidence_delta', 6, 5)->default(0.00000);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'event_type', 'created_at'], 'cog_learn_agent_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cognitive_learning_events');
        Schema::dropIfExists('cognitive_experiment_runs');
        Schema::dropIfExists('cognitive_experiments');
        Schema::dropIfExists('cognitive_state_snapshots');
        Schema::dropIfExists('cognitive_goals');
        Schema::dropIfExists('cognitive_beliefs');
        Schema::dropIfExists('cognitive_memories');
        Schema::dropIfExists('cognitive_observations');
        Schema::dropIfExists('cognitive_agents');
    }
};
