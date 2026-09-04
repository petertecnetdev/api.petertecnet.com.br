<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('organization_posts')) {
            Schema::create('organization_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->text('body');
                $table->string('status', 24)->default('published');
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['app_id', 'organization_id', 'parent_id', 'status'], 'org_posts_scope_idx');
                $table->index(['parent_id', 'created_at'], 'org_posts_reply_idx');
            });
        }

        if (! Schema::hasTable('organization_post_likes')) {
            Schema::create('organization_post_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['app_id', 'post_id', 'user_id'], 'org_post_like_unique');
                $table->index(['app_id', 'post_id'], 'org_post_like_idx');
            });
        }

        if (! Schema::hasTable('organization_media')) {
            Schema::create('organization_media', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('path', 1024);
                $table->string('caption', 180)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
                $table->index(['app_id', 'organization_id', 'position'], 'org_media_scope_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_post_likes');
        Schema::dropIfExists('organization_posts');
        Schema::dropIfExists('organization_media');
    }
};
