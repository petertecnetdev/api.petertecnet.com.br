<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_issues', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 32)->unique();
            $table->string('title', 255);
            $table->string('category', 48)->default('unknown')->index();
            $table->string('domain', 80)->nullable()->index();
            $table->string('status', 32)->default('new')->index();
            $table->string('severity', 24)->default('attention')->index();
            $table->unsignedSmallInteger('impact_score')->default(0)->index();
            $table->unsignedBigInteger('occurrence_count')->default(0);
            $table->unsignedBigInteger('users_affected_count')->default(0);
            $table->unsignedInteger('applications_affected_count')->default(0);
            $table->unsignedBigInteger('establishments_affected_count')->default(0);
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->timestamp('monitoring_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('latest_interaction_id')->nullable()->index();
            $table->unsignedBigInteger('latest_application_id')->nullable()->index();
            $table->unsignedSmallInteger('latest_http_status')->nullable()->index();
            $table->string('latest_error_code', 120)->nullable();
            $table->string('latest_method', 12)->nullable();
            $table->string('latest_route', 500)->nullable();
            $table->text('latest_message')->nullable();
            $table->string('source_version', 120)->nullable();
            $table->string('source_commit', 80)->nullable();
            $table->unsignedInteger('regression_count')->default(0);
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['status', 'impact_score']);
            $table->index(['severity', 'last_seen_at']);
            $table->index(['domain', 'status']);
        });

        Schema::create('operational_issue_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_issue_id')->constrained('operational_issues')->cascadeOnDelete();
            $table->unsignedBigInteger('interaction_id');
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('establishment_id')->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id', 120)->nullable()->index();
            $table->string('correlation_id', 120)->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['operational_issue_id', 'interaction_id'], 'operational_issue_interaction_unique');
            $table->index(['operational_issue_id', 'occurred_at'], 'operational_issue_occurred_index');
            $table->index(['application_id', 'occurred_at'], 'operational_occurrence_application_index');
        });

        Schema::create('operational_issue_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_issue_id')->constrained('operational_issues')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operational_issue_id', 'created_at'], 'operational_issue_transition_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_issue_transitions');
        Schema::dropIfExists('operational_issue_occurrences');
        Schema::dropIfExists('operational_issues');
    }
};
