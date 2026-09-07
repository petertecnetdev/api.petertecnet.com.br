<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('social_posts')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('type', 32)->default('text');
            $table->string('media_type', 24)->nullable();
            $table->string('media_path', 2048)->nullable();
            $table->string('thumbnail_path', 2048)->nullable();
            $table->string('location_name', 180)->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('status', 24)->default('published');
            $table->string('visibility', 24)->default('public');
            $table->string('source', 64)->default('timeline');
            $table->string('campaign', 120)->nullable();
            $table->foreignId('promoter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_promoted')->default(false);
            $table->timestamp('promoted_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status', 'published_at'], 'social_posts_feed_idx');
            $table->index(['app_id', 'event_id', 'status'], 'social_posts_event_idx');
            $table->index(['app_id', 'user_id', 'status'], 'social_posts_user_idx');
            $table->index(['app_id', 'is_promoted', 'promoted_until'], 'social_posts_promoted_idx');
        });

        Schema::create('social_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reaction', 24)->default('like');
            $table->timestamps();
            $table->unique(['app_id', 'post_id', 'user_id'], 'social_post_reaction_unique');
            $table->index(['app_id', 'post_id', 'reaction'], 'social_post_reactions_idx');
        });

        Schema::create('social_post_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['app_id', 'post_id', 'user_id'], 'social_post_save_unique');
        });

        Schema::create('social_post_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 32)->default('copy');
            $table->string('source', 64)->default('timeline');
            $table->string('campaign', 120)->nullable();
            $table->foreignId('promoter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['app_id', 'post_id', 'created_at'], 'social_post_shares_idx');
        });

        Schema::create('social_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('question', 500);
            $table->boolean('allows_multiple')->default(false);
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'post_id'], 'social_poll_post_unique');
        });

        Schema::create('social_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('social_polls')->cascadeOnDelete();
            $table->string('label', 240);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['poll_id', 'sort_order']);
        });

        Schema::create('social_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('poll_id')->constrained('social_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('social_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['app_id', 'poll_id', 'option_id', 'user_id'], 'social_poll_vote_unique');
            $table->index(['app_id', 'poll_id', 'user_id'], 'social_poll_user_idx');
        });

        Schema::create('social_post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'post_id', 'user_id'], 'social_post_report_unique');
            $table->index(['app_id', 'status', 'created_at'], 'social_post_reports_queue_idx');
        });

        Schema::create('social_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('opens')->default(0);
            $table->unsignedBigInteger('event_clicks')->default(0);
            $table->unsignedBigInteger('ticket_clicks')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->unsignedBigInteger('conversions')->default(0);
            $table->unsignedBigInteger('gmv_cents')->default(0);
            $table->unsignedBigInteger('platform_revenue_cents')->default(0);
            $table->timestamps();
            $table->unique(['app_id', 'post_id'], 'social_post_metrics_unique');
        });

        Schema::create('social_post_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('commerce_orders')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('session_key', 64)->nullable();
            $table->unsignedBigInteger('value_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['app_id', 'post_id', 'event_type', 'created_at'], 'social_post_events_metric_idx');
            $table->index(['app_id', 'session_key', 'created_at'], 'social_post_events_session_idx');
            $table->unique(['app_id', 'post_id', 'order_id', 'event_type'], 'social_post_order_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_events');
        Schema::dropIfExists('social_post_metrics');
        Schema::dropIfExists('social_post_reports');
        Schema::dropIfExists('social_poll_votes');
        Schema::dropIfExists('social_poll_options');
        Schema::dropIfExists('social_polls');
        Schema::dropIfExists('social_post_shares');
        Schema::dropIfExists('social_post_saves');
        Schema::dropIfExists('social_post_reactions');
        Schema::dropIfExists('social_posts');
    }
};