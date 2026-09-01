<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cutinapp_event_ratings')) {
            Schema::create('cutinapp_event_ratings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedTinyInteger('rating');
                $table->boolean('verified_attendee')->default(false);
                $table->timestamps();
                $table->unique(['app_id', 'event_id', 'user_id'], 'cut_evt_rating_unique');
                $table->index(['app_id', 'event_id', 'rating'], 'cut_evt_rating_idx');
            });
        }

        if (! Schema::hasTable('cutinapp_event_reports')) {
            Schema::create('cutinapp_event_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->string('reason', 40);
                $table->text('details')->nullable();
                $table->string('status', 24)->default('open');
                $table->text('moderation_note')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'event_id', 'user_id'], 'cut_evt_report_unique');
                $table->index(['app_id', 'status', 'created_at'], 'cut_evt_report_status_idx');
            });
        }

        if (! Schema::hasTable('cutinapp_event_posts')) {
            Schema::create('cutinapp_event_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->text('body');
                $table->string('status', 24)->default('published');
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['app_id', 'event_id', 'parent_id', 'status'], 'cut_evt_posts_idx');
                $table->index(['parent_id', 'created_at'], 'cut_evt_reply_idx');
            });
        }

        if (! Schema::hasTable('cutinapp_event_post_likes')) {
            Schema::create('cutinapp_event_post_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['app_id', 'post_id', 'user_id'], 'cut_evt_post_like_unique');
                $table->index(['app_id', 'post_id'], 'cut_evt_post_like_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_event_post_likes');
        Schema::dropIfExists('cutinapp_event_posts');
        Schema::dropIfExists('cutinapp_event_reports');
        Schema::dropIfExists('cutinapp_event_ratings');
    }
};
