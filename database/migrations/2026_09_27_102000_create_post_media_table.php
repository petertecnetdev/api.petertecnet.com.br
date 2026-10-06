<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('post_media')) {
            return;
        }

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16);
            $table->string('mime_type', 120);
            $table->string('storage_disk', 40)->default('public');
            $table->string('storage_path', 1024);
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('alt_text', 255)->nullable();
            $table->string('status', 24)->default('published');
            $table->timestamps();

            $table->unique(['app_id', 'post_id', 'position'], 'post_media_position_unique');
            $table->index(['app_id', 'post_id', 'status', 'position'], 'post_media_post_idx');
            $table->index(['app_id', 'user_id', 'created_at'], 'post_media_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
    }
};
