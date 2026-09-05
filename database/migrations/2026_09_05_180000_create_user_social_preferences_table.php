<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_social_preferences')) {
            return;
        }

        Schema::create('user_social_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('discoverable')->default(true)->index();
            $table->boolean('show_city')->default(true);
            $table->boolean('show_interests')->default(true);
            $table->boolean('show_event_interests')->default(true);
            $table->boolean('allow_follows')->default(true);
            $table->timestamps();

            $table->unique(['app_id', 'user_id'], 'user_social_preferences_app_user_unique');
            $table->index(['app_id', 'discoverable', 'allow_follows'], 'user_social_preferences_discovery_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_preferences');
    }
};
