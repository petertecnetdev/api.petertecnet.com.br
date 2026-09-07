<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->select(['id', 'title'])
            ->whereNotNull('title')
            ->orderBy('id')
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $normalizedTitle = mb_strtoupper(trim((string) $event->title), 'UTF-8');

                    if ($normalizedTitle === $event->title) {
                        continue;
                    }

                    DB::table('events')
                        ->where('id', $event->id)
                        ->update(['title' => $normalizedTitle]);
                }
            });
    }

    public function down(): void
    {
        // A capitalização original não pode ser reconstruída com segurança.
    }
};
