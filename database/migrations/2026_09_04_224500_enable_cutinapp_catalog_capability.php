<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('applications') || ! Schema::hasColumn('applications', 'capabilities')) {
            return;
        }

        $application = DB::table('applications')
            ->where('slug', 'cutinapp')
            ->first(['id', 'capabilities']);

        if (! $application) {
            return;
        }

        $capabilities = $this->decodeCapabilities($application->capabilities ?? null);

        if (! in_array('catalog', $capabilities, true)) {
            $capabilities[] = 'catalog';
            sort($capabilities);

            DB::table('applications')
                ->where('id', $application->id)
                ->update([
                    'capabilities' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive by design. `catalog` may already be in active use and
        // rollback must not revoke a capability that could have been enabled
        // independently after this migration ran.
    }

    /** @return list<string> */
    private function decodeCapabilities(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeCapabilities($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? $this->normalizeCapabilities($decoded)
            : [];
    }

    /** @return list<string> */
    private function normalizeCapabilities(array $capabilities): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($capability) => trim((string) $capability),
            $capabilities,
        ))));
    }
};
