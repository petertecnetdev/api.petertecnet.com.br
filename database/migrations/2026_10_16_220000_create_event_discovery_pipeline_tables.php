<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_discovery_sources')) {
            Schema::create('event_discovery_sources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->string('name', 160);
                $table->string('format', 16)->default('json');
                $table->string('url', 2048);
                $table->char('source_key', 64);
                $table->string('default_city', 160)->nullable();
                $table->char('default_uf', 2)->nullable();
                $table->string('timezone', 64)->default('America/Sao_Paulo');
                $table->boolean('enabled')->default(false);
                $table->timestamp('last_scanned_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'source_key'], 'discovery_source_unique');
                $table->index(['enabled', 'last_scanned_at'], 'discovery_source_schedule');
            });
        }

        if (! Schema::hasTable('event_discovery_candidates')) {
            Schema::create('event_discovery_candidates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('source_id')->index();
                $table->char('source_event_key', 64);
                $table->char('content_key', 64);
                $table->string('title', 240);
                $table->text('description')->nullable();
                $table->dateTime('start_at');
                $table->dateTime('end_at')->nullable();
                $table->string('city', 160);
                $table->char('uf', 2);
                $table->string('venue', 255)->nullable();
                $table->string('address', 500)->nullable();
                $table->string('organizer', 200)->nullable();
                $table->string('source_url', 2048);
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('event_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('last_seen_at');
                $table->json('evidence')->nullable();
                $table->timestamps();
                $table->unique(['source_id', 'source_event_key'], 'discovery_candidate_source_unique');
                $table->index(['app_id', 'content_key'], 'discovery_candidate_content');
                $table->index(['app_id', 'status', 'start_at'], 'discovery_candidate_review');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_discovery_candidates');
        Schema::dropIfExists('event_discovery_sources');
    }
};
