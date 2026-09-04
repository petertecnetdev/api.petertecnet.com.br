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

        foreach ((array) config('platform.applications', []) as $slug => $definition) {
            $configured = $this->normalizeCapabilities((array) ($definition['capabilities'] ?? []));
            if ($configured === []) {
                continue;
            }

            $application = DB::table('applications')
                ->where('slug', (string) $slug)
                ->first(['id', 'capabilities']);

            if (! $application) {
                continue;
            }

            $persisted = $this->decodeCapabilities($application->capabilities ?? null);
            $reconciled = array_values(array_unique([...$persisted, ...$configured]));
            sort($reconciled);

            $current = $persisted;
            sort($current);

            if ($current === $reconciled) {
                continue;
            }

            DB::table('applications')
                ->where('id', $application->id)
                ->update([
                    'capabilities' => json_encode($reconciled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive by design: existing capabilities may have been added
        // independently and cannot be safely reconstructed during rollback.
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
