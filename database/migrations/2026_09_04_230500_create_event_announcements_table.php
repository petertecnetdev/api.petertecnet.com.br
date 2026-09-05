<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 140);
            $table->text('message');
            $table->string('level', 24)->default('info');
            $table->string('audience', 32)->default('all');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('send_notification')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'event_id', 'published_at'], 'event_announcements_public_idx');
            $table->index(['event_id', 'is_pinned', 'published_at'], 'event_announcements_order_idx');
            $table->index(['starts_at', 'ends_at'], 'event_announcements_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_announcements');
    }
};
