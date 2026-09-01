<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        foreach ([
            'is_private' => false,
            'is_published' => false,
            'is_cancelled' => false,
            'is_featured' => false,
            'is_approved' => false,
            'requires_approval' => false,
        ] as $column => $default) {
            if (Schema::hasColumn('events', $column)) {
                DB::table('events')->whereNull($column)->update([$column => $default]);
            }
        }
    }

    public function down(): void
    {
        // Data normalization is intentionally non-destructive and is not reverted.
    }
};
