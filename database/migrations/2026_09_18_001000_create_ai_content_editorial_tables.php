<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_content_states')) {
            Schema::create('ai_content_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->string('entity_type', 50);
                $table->unsignedBigInteger('entity_id');
                $table->longText('user_draft')->nullable();
                $table->longText('latest_ai_text')->nullable();
                $table->unsignedBigInteger('latest_generation_id')->nullable()->index();
                $table->longText('accepted_text')->nullable();
                $table->string('prompt_version', 80)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'entity_type', 'entity_id'], 'ai_content_states_entity_unique');
            });
        }

        if (! Schema::hasTable('ai_content_generations')) {
            Schema::create('ai_content_generations', function (Blueprint $table) {
                $table->id();
                $table->uuid('group_id')->index();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('entity_type', 50)->index();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->string('action', 32)->default('improve');
                $table->unsignedTinyInteger('candidate_index')->default(0);
                $table->string('prompt_version', 80);
                $table->string('model', 160)->nullable();
                $table->longText('draft_before')->nullable();
                $table->longText('output');
                $table->unsignedTinyInteger('quality_score')->default(0);
                $table->json('quality_details')->nullable();
                $table->string('status', 32)->default('candidate')->index();
                $table->string('context_hash', 64)->nullable()->index();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_editorial_profiles')) {
            Schema::create('ai_editorial_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->string('scope_type', 50);
                $table->unsignedBigInteger('scope_id');
                $table->json('traits')->nullable();
                $table->json('avoid_phrases')->nullable();
                $table->unsignedInteger('source_count')->default(0);
                $table->timestamp('source_updated_at')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'scope_type', 'scope_id'], 'ai_editorial_profiles_scope_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_editorial_profiles');
        Schema::dropIfExists('ai_content_generations');
        Schema::dropIfExists('ai_content_states');
    }
};
