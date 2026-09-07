<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['app_id', 'is_published', 'is_cancelled', 'is_private', 'start_date'], 'events_public_start_perf_idx');
            $table->index(['app_id', 'is_published', 'is_cancelled', 'is_private', 'created_at'], 'events_public_created_perf_idx');
            $table->index(['app_id', 'city', 'uf', 'start_date'], 'events_city_start_perf_idx');
            $table->index(['app_id', 'category', 'start_date'], 'events_category_start_perf_idx');
        });

        Schema::table('event_posts', function (Blueprint $table) {
            $table->index(['app_id', 'status', 'parent_id', 'created_at'], 'event_posts_feed_perf_idx');
        });

        Schema::table('event_passes', function (Blueprint $table) {
            $table->index(['user_id', 'event_id', 'status'], 'event_passes_user_event_perf_idx');
            $table->index(['ticket_id', 'status'], 'event_passes_ticket_status_perf_idx');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['app_id', 'event_id', 'price', 'limit_date'], 'tickets_event_available_perf_idx');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_public_start_perf_idx');
            $table->dropIndex('events_public_created_perf_idx');
            $table->dropIndex('events_city_start_perf_idx');
            $table->dropIndex('events_category_start_perf_idx');
        });
        Schema::table('event_posts', fn (Blueprint $table) => $table->dropIndex('event_posts_feed_perf_idx'));
        Schema::table('event_passes', function (Blueprint $table) {
            $table->dropIndex('event_passes_user_event_perf_idx');
            $table->dropIndex('event_passes_ticket_status_perf_idx');
        });
        Schema::table('tickets', fn (Blueprint $table) => $table->dropIndex('tickets_event_available_perf_idx'));
    }
};
