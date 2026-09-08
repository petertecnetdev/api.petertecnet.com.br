<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateCutinappCapabilities(
            static fn (array $capabilities): array => array_values(array_unique([
                ...$capabilities,
                'catalog',
            ])),
        );
    }

    public function down(): void
    {
        $this->updateCutinappCapabilities(
            static fn (array $capabilities): array => array_values(array_filter(
                $capabilities,
                static fn (string $capability): bool => $capability !== 'catalog',
            )),
        );
    }

    private function updateCutinappCapabilities(callable $transform): void
    {
        if (
            ! Schema::hasTable('applications')
            || ! Schema::hasColumn('applications', 'slug')
            || ! Schema::hasColumn('applications', 'capabilities')
        ) {
            return;
        }

        $application = DB::table('applications')
            ->where('slug', 'cutinapp')
            ->first(['id', 'capabilities']);

        if (! $application) {
            return;
        }

        $capabilities = json_decode((string) ($application->capabilities ?? '[]'), true);
        if (! is_array($capabilities)) {
            $capabilities = [];
        }

        $capabilities = array_values(array_unique(array_filter(array_map(
            static fn ($capability): string => trim((string) $capability),
            $transform($capabilities),
        ))));

        $payload = [
            'capabilities' => json_encode(
                $capabilities,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ];

        if (Schema::hasColumn('applications', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('applications')
            ->where('id', $application->id)
            ->update($payload);
    }
};
