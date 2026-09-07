<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('budget_cents');
            $table->unsignedBigInteger('spent_cents')->default(0);
            $table->string('status', 24)->default('requested');
            $table->string('target_city', 120)->nullable();
            $table->unsignedSmallInteger('target_radius_km')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'status', 'starts_at', 'ends_at'], 'social_boosts_delivery_idx');
            $table->index(['app_id', 'post_id', 'status'], 'social_boosts_post_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_boosts');
    }
};
