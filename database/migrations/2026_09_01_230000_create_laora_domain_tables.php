<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laora_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 80);
            $table->date('birthdate');
            $table->string('gender', 40)->nullable();
            $table->string('orientation', 40)->nullable();
            $table->text('bio')->nullable();
            $table->json('interests')->nullable();
            $table->string('city', 120)->nullable();
            $table->char('uf', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('age_min')->default(18);
            $table->unsignedTinyInteger('age_max')->default(99);
            $table->unsignedSmallInteger('max_distance_km')->default(80);
            $table->json('preferred_genders')->nullable();
            $table->boolean('discovery_enabled')->default(true);
            $table->boolean('is_complete')->default(false);
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamps();
            $table->index(['discovery_enabled', 'city', 'uf']);
        });

        Schema::create('laora_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('laora_profiles')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('moderation_status', 20)->default('approved');
            $table->timestamps();
            $table->index(['profile_id', 'position']);
        });

        Schema::create('laora_swipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swiper_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 10);
            $table->timestamps();
            $table->unique(['swiper_user_id', 'target_user_id']);
            $table->index(['target_user_id', 'action']);
        });

        Schema::create('laora_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('matched_at');
            $table->timestamp('unmatched_at')->nullable();
            $table->foreignId('unmatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_one_id', 'user_two_id']);
            $table->index(['status', 'matched_at']);
        });

        Schema::create('laora_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('laora_matches')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['match_id', 'created_at']);
            $table->index(['sender_user_id', 'read_at']);
        });

        Schema::create('laora_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 80)->nullable();
            $table->timestamps();
            $table->unique(['blocker_user_id', 'blocked_user_id']);
        });

        Schema::create('laora_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('laora_matches')->nullOnDelete();
            $table->string('reason', 80);
            $table->text('details')->nullable();
            $table->string('status', 30)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('laora_moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained('laora_reports')->nullOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('moderator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('reason', 160)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['target_user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laora_moderation_actions');
        Schema::dropIfExists('laora_reports');
        Schema::dropIfExists('laora_blocks');
        Schema::dropIfExists('laora_messages');
        Schema::dropIfExists('laora_matches');
        Schema::dropIfExists('laora_swipes');
        Schema::dropIfExists('laora_photos');
        Schema::dropIfExists('laora_profiles');
    }
};
