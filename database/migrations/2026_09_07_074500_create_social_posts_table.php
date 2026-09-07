<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_posts')) {
            return;
        }

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('user_id');
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('body');
            $table->string('status', 24)->default('published');
            $table->string('visibility', 24)->default('public');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['app_id', 'status', 'visibility', 'created_at'], 'social_posts_feed_idx');
            $table->index(['app_id', 'user_id', 'created_at'], 'social_posts_author_idx');
            $table->index(['app_id', 'subject_type', 'subject_id'], 'social_posts_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
