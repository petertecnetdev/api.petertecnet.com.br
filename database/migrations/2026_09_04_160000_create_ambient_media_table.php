<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambient_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('slot', 40)->default('background_music');
            $table->string('provider', 24);
            $table->text('source_url');
            $table->string('external_id', 160)->nullable();
            $table->string('title')->nullable();
            $table->string('artist')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('autoplay')->default(true);
            $table->boolean('loop')->default(true);
            $table->unsignedTinyInteger('volume')->default(35);
            $table->unsignedInteger('start_seconds')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'subject_type', 'subject_id', 'slot'], 'ambient_media_subject_slot_unique');
            $table->index(['app_id', 'subject_type', 'subject_id'], 'ambient_media_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambient_media');
    }
};
