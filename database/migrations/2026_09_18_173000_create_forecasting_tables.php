<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->text('original_statement');
            $table->text('statement');
            $table->text('summary')->nullable();
            $table->string('category', 100)->default('Geral')->index();
            $table->json('topics')->nullable();
            $table->json('entities')->nullable();
            $table->timestamp('deadline_at')->index();
            $table->timestamp('locked_at')->nullable()->index();
            $table->string('status', 32)->default('draft')->index();
            $table->string('visibility', 20)->default('public')->index();
            $table->decimal('author_probability', 5, 2)->nullable();
            $table->decimal('community_probability', 5, 2)->nullable();
            $table->decimal('platform_probability', 5, 2)->nullable();
            $table->decimal('platform_confidence', 5, 2)->nullable();
            $table->text('resolution_criteria');
            $table->json('resolution_source_requirements')->nullable();
            $table->json('analysis')->nullable();
            $table->string('model_version', 100)->nullable();
            $table->string('moderation_status', 32)->default('approved')->index();
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->unsignedInteger('follow_count')->default(0);
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['app_id', 'status', 'deadline_at']);
            $table->index(['app_id', 'category', 'status']);
        });

        Schema::create('forecast_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version_no');
            $table->text('statement');
            $table->decimal('author_probability', 5, 2)->nullable();
            $table->timestamp('deadline_at');
            $table->text('resolution_criteria');
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['forecast_id', 'version_no']);
        });

        Schema::create('forecast_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('probability', 5, 2);
            $table->text('rationale')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['forecast_id', 'user_id', 'id']);
        });

        Schema::create('forecast_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stance', 20)->default('context');
            $table->string('title', 255);
            $table->text('url');
            $table->string('source_name', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('excerpt')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('forecast_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->string('outcome', 24);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('rationale')->nullable();
            $table->json('sources')->nullable();
            $table->string('proposed_by', 32)->default('system');
            $table->string('model_version', 100)->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_final')->default(false)->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['forecast_id', 'is_final']);
        });

        Schema::create('forecast_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('forecast_comments')->nullOnDelete();
            $table->text('body');
            $table->string('moderation_status', 24)->default('approved')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('forecast_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['forecast_id', 'user_id']);
        });

        Schema::create('forecast_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 80);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forecast_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('forecasts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 24)->default('open')->index();
            $table->text('response')->nullable();
            $table->timestamps();
        });

        Schema::create('user_reputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 100)->default('all');
            $table->decimal('score', 6, 2)->default(50);
            $table->decimal('calibration_score', 6, 2)->default(50);
            $table->decimal('brier_score', 8, 6)->nullable();
            $table->decimal('confidence', 6, 2)->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('average_lead_days', 10, 2)->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'user_id', 'category']);
            $table->index(['app_id', 'category', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reputations');
        Schema::dropIfExists('forecast_disputes');
        Schema::dropIfExists('forecast_reports');
        Schema::dropIfExists('forecast_follows');
        Schema::dropIfExists('forecast_comments');
        Schema::dropIfExists('forecast_resolutions');
        Schema::dropIfExists('forecast_evidence');
        Schema::dropIfExists('forecast_estimates');
        Schema::dropIfExists('forecast_versions');
        Schema::dropIfExists('forecasts');
    }
};
