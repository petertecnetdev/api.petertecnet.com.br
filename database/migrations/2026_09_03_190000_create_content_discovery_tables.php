<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_entries')) {
            Schema::create('content_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 32)->default('article')->index();
                $table->string('status', 24)->default('draft')->index();
                $table->string('title', 220);
                $table->string('slug', 220)->index();
                $table->text('excerpt')->nullable();
                $table->longText('content')->nullable();
                $table->string('category', 120)->nullable()->index();
                $table->json('tags')->nullable();
                $table->string('cluster', 140)->nullable()->index();
                $table->string('search_intent', 180)->nullable();
                $table->string('cover_image', 500)->nullable();
                $table->string('og_image', 500)->nullable();
                $table->string('seo_title', 220)->nullable();
                $table->text('seo_description')->nullable();
                $table->string('canonical_url', 500)->nullable();
                $table->json('related')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['application_id', 'type', 'slug'], 'content_entries_app_type_slug_unique');
                $table->index(['status', 'published_at'], 'content_entries_status_published');
                $table->index(['application_id', 'status', 'type'], 'content_entries_app_status_type');
            });
        }

        if (! Schema::hasTable('discovery_events')) {
            Schema::create('discovery_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('session_id', 80)->nullable()->index();
                $table->string('event_type', 48)->index();
                $table->string('entity_type', 48)->nullable()->index();
                $table->string('entity_id', 180)->nullable();
                $table->string('path', 500)->nullable();
                $table->string('source', 80)->nullable()->index();
                $table->string('referrer_host', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['application_id', 'event_type', 'occurred_at'], 'discovery_events_app_type_time');
                $table->index(['entity_type', 'entity_id', 'occurred_at'], 'discovery_events_entity_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_events');
        Schema::dropIfExists('content_entries');
    }
};
