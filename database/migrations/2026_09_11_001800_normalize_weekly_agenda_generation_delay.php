<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_schedules')
            ->where('generation_delay_days', '>', 6)
            ->update(['generation_delay_days' => 6]);
    }

    public function down(): void
    {
        // The previous value cannot be inferred safely; keep normalized data.
    }
};
