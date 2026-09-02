<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('applications') || ! Schema::hasTable('establishments') || ! Schema::hasTable('items')) {
            return;
        }

        $nexusAppId = DB::table('applications')
            ->where('slug', 'nexus')
            ->value('id');

        if (! $nexusAppId) {
            return;
        }

        $establishmentIds = DB::table('establishments')
            ->where('slug', 'peter-tecnet')
            ->pluck('id');

        if ($establishmentIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($nexusAppId, $establishmentIds) {
            $itemIds = DB::table('items')
                ->where('entity_name', 'establishment')
                ->whereIn('entity_id', $establishmentIds)
                ->pluck('id');

            DB::table('establishments')
                ->whereIn('id', $establishmentIds)
                ->update([
                    'app_id' => $nexusAppId,
                    'updated_at' => now(),
                ]);

            DB::table('items')
                ->where('entity_name', 'establishment')
                ->whereIn('entity_id', $establishmentIds)
                ->update([
                    'app_id' => $nexusAppId,
                    'updated_at' => now(),
                ]);

            if (Schema::hasTable('files') && Schema::hasColumn('files', 'app_id')) {
                DB::table('files')
                    ->where('entity_name', 'establishment')
                    ->whereIn('entity_id', $establishmentIds)
                    ->update(['app_id' => $nexusAppId]);

                if ($itemIds->isNotEmpty()) {
                    DB::table('files')
                        ->where('entity_name', 'item')
                        ->whereIn('entity_id', $itemIds)
                        ->update(['app_id' => $nexusAppId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Data repair: intentionally not reverted to the inconsistent application mapping.
    }
};
