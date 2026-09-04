<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('social_posts')) {
            Schema::create('social_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('scope_type', 24)->default('global');
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->text('body');
                $table->string('status', 24)->default('published');
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['app_id', 'scope_type', 'scope_id', 'parent_id', 'status', 'created_at'], 'social_posts_scope_idx');
                $table->index(['app_id', 'user_id', 'status', 'created_at'], 'social_posts_user_idx');
                $table->index(['parent_id', 'created_at'], 'social_posts_reply_idx');
            });
        }

        if (! Schema::hasTable('social_post_likes')) {
            Schema::create('social_post_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['app_id', 'post_id', 'user_id'], 'social_post_like_unique');
                $table->index(['app_id', 'post_id'], 'social_post_like_idx');
            });
        }

        if (! Schema::hasTable('social_post_views')) {
            Schema::create('social_post_views', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->string('source_type', 24);
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->char('visitor_key', 64);
                $table->timestamp('viewed_at');
                $table->timestamps();
                $table->index(['app_id', 'source_type', 'post_id', 'viewed_at'], 'social_post_views_lookup_idx');
                $table->index(['app_id', 'source_type', 'post_id', 'user_id'], 'social_post_views_user_idx');
                $table->index(['source_type', 'post_id', 'visitor_key', 'viewed_at'], 'social_post_views_dedupe_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_views');
        Schema::dropIfExists('social_post_likes');
        Schema::dropIfExists('social_posts');
    }
};
