<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        return Schema::hasTable($table)
            && collect(Schema::getIndexes($table))
                ->contains(fn (array $metadata) => ($metadata['name'] ?? null) === $index);
    }

    public function up(): void
    {
        if (Schema::hasTable('establishments')) {
            Schema::table('establishments', function (Blueprint $table) {
                if (! $this->indexExists('establishments', 'est_public_discovery_idx')) {
                    $table->index(
                        ['is_cancelled', 'is_published', 'is_featured', 'updated_at', 'id'],
                        'est_public_discovery_idx'
                    );
                }
                if (! $this->indexExists('establishments', 'est_public_location_idx')) {
                    $table->index(['city', 'uf', 'is_published', 'is_cancelled'], 'est_public_location_idx');
                }
            });
        }

        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table) {
                if (! $this->indexExists('items', 'items_public_catalog_idx')) {
                    $table->index(
                        ['entity_name', 'entity_id', 'status', 'is_featured', 'updated_at', 'id'],
                        'items_public_catalog_idx'
                    );
                }
            });
        }

        if (Schema::hasTable('interactions')) {
            Schema::table('interactions', function (Blueprint $table) {
                if (! $this->indexExists('interactions', 'interactions_entity_type_kind_idx')) {
                    $table->index(
                        ['entity_type', 'entity_id', 'interaction_type'],
                        'interactions_entity_type_kind_idx'
                    );
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'establishments' => ['est_public_discovery_idx', 'est_public_location_idx'],
            'items' => ['items_public_catalog_idx'],
            'interactions' => ['interactions_entity_type_kind_idx'],
        ] as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes) {
                foreach ($indexes as $index) {
                    if ($this->indexExists($tableName, $index)) {
                        $table->dropIndex($index);
                    }
                }
            });
        }
    }
};
