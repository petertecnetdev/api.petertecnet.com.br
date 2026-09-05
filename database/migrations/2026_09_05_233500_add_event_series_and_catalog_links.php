<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'event_series_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->uuid('event_series_id')->nullable()->after('event_schedule_id');
                $table->index(['app_id', 'production_id', 'event_series_id'], 'events_app_production_series_idx');
            });
        }

        if (Schema::hasTable('event_schedules') && ! Schema::hasColumn('event_schedules', 'event_series_id')) {
            Schema::table('event_schedules', function (Blueprint $table) {
                $table->uuid('event_series_id')->nullable()->after('production_id');
                $table->index(['app_id', 'production_id', 'event_series_id'], 'event_schedules_app_production_series_idx');
            });
        }

        $eventItemsTable = $this->eventItemsTable();
        if ($eventItemsTable && ! Schema::hasColumn($eventItemsTable, 'source_item_id')) {
            Schema::table($eventItemsTable, function (Blueprint $table) {
                $table->unsignedBigInteger('source_item_id')->nullable()->after('event_id');
                $table->index('source_item_id', 'event_items_source_item_idx');
                $table->unique(['event_id', 'source_item_id'], 'event_items_event_source_item_unique');
            });
        }

        $this->refreshEventItemsView();

        if (Schema::hasTable('event_schedules') && Schema::hasColumn('event_schedules', 'event_series_id')) {
            DB::table('event_schedules')
                ->select(['id'])
                ->whereNull('event_series_id')
                ->orderBy('id')
                ->chunkById(100, function ($schedules): void {
                    foreach ($schedules as $schedule) {
                        $seriesId = (string) Str::uuid();
                        DB::table('event_schedules')->where('id', $schedule->id)->update([
                            'event_series_id' => $seriesId,
                        ]);

                        if (Schema::hasColumn('events', 'event_schedule_id')) {
                            DB::table('events')
                                ->where('event_schedule_id', $schedule->id)
                                ->whereNull('event_series_id')
                                ->update(['event_series_id' => $seriesId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        $eventItemsTable = $this->eventItemsTable();
        if ($eventItemsTable && Schema::hasColumn($eventItemsTable, 'source_item_id')) {
            Schema::table($eventItemsTable, function (Blueprint $table) {
                $table->dropUnique('event_items_event_source_item_unique');
                $table->dropIndex('event_items_source_item_idx');
                $table->dropColumn('source_item_id');
            });
        }

        $this->refreshEventItemsView();

        if (Schema::hasTable('event_schedules') && Schema::hasColumn('event_schedules', 'event_series_id')) {
            Schema::table('event_schedules', function (Blueprint $table) {
                $table->dropIndex('event_schedules_app_production_series_idx');
                $table->dropColumn('event_series_id');
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'event_series_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropIndex('events_app_production_series_idx');
                $table->dropColumn('event_series_id');
            });
        }
    }

    private function eventItemsTable(): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable('event_items') ? 'event_items' : null;
        }

        return Schema::hasTable('cutinapp_event_items') ? 'cutinapp_event_items' : null;
    }

    private function refreshEventItemsView(): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable('cutinapp_event_items')) {
            return;
        }

        DB::statement('CREATE OR REPLACE ALGORITHM=MERGE VIEW `event_items` AS SELECT * FROM `cutinapp_event_items`');
    }
};
