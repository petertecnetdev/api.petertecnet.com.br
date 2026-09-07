<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('important_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('type', 120);
            $table->string('severity', 24)->default('info');
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_type', 80)->nullable();
            $table->string('reference_id', 120)->nullable();
            $table->text('reference_url')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['app_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index(['severity', 'occurred_at']);
        });

        Schema::create('important_event_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('important_event_id')->constrained('important_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['important_event_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('important_event_reads');
        Schema::dropIfExists('important_events');
    }
};
