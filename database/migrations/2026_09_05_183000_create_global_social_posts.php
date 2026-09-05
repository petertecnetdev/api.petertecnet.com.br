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
            $table->text('body')->nullable();
            $table->string('status', 24)->default('published');
            $table->timestamps();

            $table->index(['app_id', 'status', 'created_at']);
            $table->index(['app_id', 'user_id', 'created_at']);
        });

        Schema::create('social_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['app_id', 'post_id', 'position']);
        });

        Schema::create('social_post_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->unique()->constrained('social_posts')->cascadeOnDelete();
            $table->string('question', 300);
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'created_at']);
        });

        Schema::create('social_post_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('poll_id')->constrained('social_post_polls')->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['app_id', 'poll_id', 'position']);
        });

        Schema::create('social_post_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('poll_id')->constrained('social_post_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('social_post_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
            $table->index(['app_id', 'poll_id', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_poll_votes');
        Schema::dropIfExists('social_post_poll_options');
        Schema::dropIfExists('social_post_polls');
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_posts');
    }
};
