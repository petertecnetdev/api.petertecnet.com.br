<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduling_resources')
            || ! Schema::hasTable('scheduling_resource_item')
            || ! Schema::hasTable('employer_item')
            || ! Schema::hasTable('items')) {
            return;
        }

        DB::table('scheduling_resources')
            ->join('employer_item', 'employer_item.employer_id', '=', 'scheduling_resources.employer_id')
            ->join('items', 'items.id', '=', 'employer_item.item_id')
            ->where('scheduling_resources.type', 'professional')
            ->whereNotNull('scheduling_resources.employer_id')
            ->whereColumn('items.app_id', 'scheduling_resources.app_id')
            ->where('items.entity_name', 'establishment')
            ->whereColumn('items.entity_id', 'scheduling_resources.establishment_id')
            ->select([
                'scheduling_resources.id as scheduling_resource_id',
                'items.id as item_id',
            ])
            ->orderBy('scheduling_resources.id')
            ->chunk(500, function ($rows) {
                $now = now();

                DB::table('scheduling_resource_item')->insertOrIgnore(
                    $rows->map(fn ($row) => [
                        'scheduling_resource_id' => (int) $row->scheduling_resource_id,
                        'item_id' => (int) $row->item_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive. These rows represent valid professional
        // capabilities already present in employer_item and may have been updated
        // by users after this migration ran.
    }
};
