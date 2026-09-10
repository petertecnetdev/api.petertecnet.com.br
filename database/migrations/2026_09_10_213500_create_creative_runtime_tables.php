<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->index();
            $table->string('owner_type', 80);
            $table->unsignedBigInteger('owner_id');
            $table->json('brand_colors')->nullable();
            $table->string('preferred_style', 80)->nullable();
            $table->string('preferred_intensity', 40)->nullable();
            $table->text('brand_context')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'owner_type', 'owner_id'], 'creative_profiles_owner_unique');
        });

        Schema::create('creative_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('owner_type', 80)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('purpose', 100)->index();
            $table->string('subject', 180)->nullable();
            $table->string('format', 40)->nullable();
            $table->string('style', 80)->nullable();
            $table->string('intensity', 40)->nullable();
            $table->string('variation', 80)->nullable();
            $table->string('generation_mode', 30)->default('preview');
            $table->string('model', 180)->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->boolean('selected')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'owner_type', 'owner_id', 'created_at'], 'creative_generations_owner_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_generations');
        Schema::dropIfExists('creative_profiles');
    }
};
