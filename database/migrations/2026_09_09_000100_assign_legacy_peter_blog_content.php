<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('applications') || ! Schema::hasTable('content_entries')) {
            return;
        }

        $applicationId = DB::table('applications')
            ->whereIn('slug', ['peter-tecnet', 'petertecnet'])
            ->value('id');

        if (! $applicationId) {
            $applicationId = DB::table('applications')
                ->where('name', 'Peter Tecnet')
                ->value('id');
        }

        if (! $applicationId) {
            return;
        }

        $legacyEntries = DB::table('content_entries')
            ->whereNull('application_id')
            ->where('type', 'article')
            ->where('metadata->migrated_from', 'marketingContent.js')
            ->select(['id', 'slug'])
            ->get();

        foreach ($legacyEntries as $entry) {
            $alreadyScoped = DB::table('content_entries')
                ->where('application_id', $applicationId)
                ->where('type', 'article')
                ->where('slug', $entry->slug)
                ->exists();

            if ($alreadyScoped) {
                continue;
            }

            DB::table('content_entries')
                ->where('id', $entry->id)
                ->update([
                    'application_id' => $applicationId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Ownership migration is intentionally irreversible: reverting it would
        // make Peter Tecnet articles global again and reintroduce cross-app leaks.
    }
};
