<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->nullable();

            $table->unsignedBigInteger('app_id')->nullable();
            $table->foreign('app_id')->references('id')->on('applications')->nullOnDelete();

            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_name')->nullable();

            $table->unsignedBigInteger('fileable_id')->nullable();
            $table->string('fileable_type')->nullable();

            $table->string('original_name')->nullable();
            $table->string('extension')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('content_hash')->nullable();

            $table->string('type')->nullable();
            $table->string('storage')->nullable();

            $table->string('path');
            $table->string('storage_path')->nullable();
            $table->string('public_url')->nullable();

            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('quality')->nullable();
            $table->string('color_profile')->nullable();
            $table->string('orientation')->nullable();

            $table->integer('duration')->nullable();
            $table->integer('fps')->nullable();
            $table->integer('bitrate')->nullable();
            $table->integer('video_width')->nullable();
            $table->integer('video_height')->nullable();
            $table->string('codec')->nullable();

            $table->string('group')->nullable();
            $table->json('tags')->nullable();

            $table->integer('sort_order')->default(0);
            $table->integer('position')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('processed')->default(false);

            $table->json('variants')->nullable();
            $table->json('meta')->nullable();

            $table->string('visibility')->nullable();
            $table->string('visibility_scope')->nullable();
            $table->string('status')->nullable();

            $table->boolean('locked')->default(false);
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->string('source')->nullable();
            $table->string('version')->nullable();

            $table->string('checksum')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->boolean('compressed')->default(false);
            $table->decimal('compression_ratio', 10, 4)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['app_id']);
            $table->index(['entity_id', 'entity_name']);
            $table->index(['fileable_id', 'fileable_type']);
            $table->index(['type']);
            $table->index(['visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
