<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interactions') || ! Schema::hasColumn('interactions', 'app_id')) {
            return;
        }

        $this->backfillFromEntity(['Establishment', 'establishment'], 'establishments');
        $this->backfillFromEntity(['Item', 'item'], 'items');
        $this->backfillFromEntity(['Appointment', 'appointment'], 'appointments');

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'establishment_id') && Schema::hasTable('establishments')) {
            DB::table('interactions')
                ->whereNull('app_id')
                ->whereIn('entity_type', ['Order', 'order'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $orderIds = $rows->pluck('entity_id')->filter()->unique()->values();
                    if ($orderIds->isEmpty()) return;

                    $establishmentIds = DB::table('orders')->whereIn('id', $orderIds)->pluck('establishment_id', 'id');
                    $appIds = DB::table('establishments')
                        ->whereIn('id', $establishmentIds->filter()->unique()->values())
                        ->pluck('app_id', 'id');

                    foreach ($rows as $row) {
                        $establishmentId = $establishmentIds[$row->entity_id] ?? null;
                        $appId = $establishmentId ? ($appIds[$establishmentId] ?? null) : null;
                        if ($appId) DB::table('interactions')->where('id', $row->id)->update(['app_id' => $appId]);
                    }
                });
        }
    }

    private function backfillFromEntity(array $entityTypes, string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'app_id')) return;

        DB::table('interactions')
            ->whereNull('app_id')
            ->whereIn('entity_type', $entityTypes)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                $ids = $rows->pluck('entity_id')->filter()->unique()->values();
                if ($ids->isEmpty()) return;

                $map = DB::table($table)->whereIn('id', $ids)->pluck('app_id', 'id');
                foreach ($rows as $row) {
                    $appId = $map[$row->entity_id] ?? null;
                    if ($appId) DB::table('interactions')->where('id', $row->id)->update(['app_id' => $appId]);
                }
            });
    }

    public function down(): void
    {
        // Historical attribution is intentionally preserved.
    }
};
