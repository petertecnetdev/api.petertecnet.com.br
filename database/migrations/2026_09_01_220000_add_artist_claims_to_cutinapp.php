<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutinapp_artists', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('created_by_user_id');
            $table->index(['app_id', 'created_by_user_id']);
        });

        Schema::create('cutinapp_artist_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('cutinapp_artists')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('message')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'artist_id', 'event_id', 'user_id'], 'cut_artist_claim_unique');
            $table->index(['app_id', 'event_id', 'status']);
            $table->index(['app_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_artist_claims');
        Schema::table('cutinapp_artists', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropIndex(['app_id', 'created_by_user_id']);
            $table->dropColumn(['created_by_user_id', 'claimed_at']);
        });
    }
};
