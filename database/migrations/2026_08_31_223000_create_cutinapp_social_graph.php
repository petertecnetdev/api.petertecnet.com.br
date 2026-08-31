<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cutinapp_artists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('stage_name');
            $table->text('bio')->nullable();
            $table->string('city', 120)->nullable()->index();
            $table->string('uf', 2)->nullable()->index();
            $table->json('genres')->nullable();
            $table->string('photo')->nullable();
            $table->string('cover')->nullable();
            $table->string('instagram_url', 2048)->nullable();
            $table->string('youtube_url', 2048)->nullable();
            $table->string('spotify_url', 2048)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
            $table->index(['app_id', 'stage_name']);
        });

        Schema::create('cutinapp_event_artist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('cutinapp_artists')->cascadeOnDelete();
            $table->string('participation_type', 80)->default('apresentacao');
            $table->string('stage', 160)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_headliner')->default(false)->index();
            $table->timestamps();
            $table->unique(['event_id', 'artist_id']);
            $table->index(['app_id', 'event_id', 'sort_order']);
        });

        Schema::create('cutinapp_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['app_id', 'user_id', 'target_type', 'target_id'], 'cutinapp_follow_unique');
            $table->index(['app_id', 'target_type', 'target_id']);
        });

        Schema::create('cutinapp_event_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->boolean('is_favorite')->default(false)->index();
            $table->boolean('is_interested')->default(false)->index();
            $table->timestamps();
            $table->unique(['app_id', 'user_id', 'event_id']);
        });

        Schema::create('cutinapp_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('preferred_city', 120)->nullable();
            $table->string('preferred_uf', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_km')->default(50);
            $table->json('interests')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'user_id']);
            $table->index(['app_id', 'preferred_city', 'preferred_uf']);
        });

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'category')) {
                $table->string('category', 120)->nullable()->index()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'category')) {
                $table->dropColumn('category');
            }
        });
        Schema::dropIfExists('cutinapp_user_preferences');
        Schema::dropIfExists('cutinapp_event_engagements');
        Schema::dropIfExists('cutinapp_follows');
        Schema::dropIfExists('cutinapp_event_artist');
        Schema::dropIfExists('cutinapp_artists');
    }
};
