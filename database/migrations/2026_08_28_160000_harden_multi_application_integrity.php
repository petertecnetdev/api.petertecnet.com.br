<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('establishments')) {
            if (! Schema::hasColumn('establishments', 'created_by')) {
                Schema::table('establishments', function (Blueprint $table) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->after('user_id')
                        ->constrained('users')
                        ->nullOnDelete();
                });
            }

            $this->addIndexIfMissing(
                'establishments',
                'establishments_app_user_cancelled_idx',
                ['app_id', 'user_id', 'is_cancelled']
            );
            $this->addIndexIfMissing(
                'establishments',
                'establishments_app_city_uf_cancelled_idx',
                ['app_id', 'city', 'uf', 'is_cancelled']
            );
        }

        if (Schema::hasTable('items')) {
            $this->addIndexIfMissing(
                'items',
                'items_app_entity_status_idx',
                ['app_id', 'entity_name', 'entity_id', 'status']
            );
            $this->addIndexIfMissing(
                'items',
                'items_app_type_status_idx',
                ['app_id', 'type', 'status']
            );
            $this->addIndexIfMissing(
                'items',
                'items_app_slug_idx',
                ['app_id', 'slug']
            );
        }

        if (Schema::hasTable('files')) {
            $columns = ['app_id', 'entity_name', 'entity_id'];
            if ($this->hasColumns('files', $columns)) {
                $this->addIndexIfMissing('files', 'files_app_entity_idx', $columns);
            }
        }

        if (Schema::hasTable('service_records')) {
            $this->addIndexIfMissing(
                'service_records',
                'service_records_app_status_created_idx',
                ['app_id', 'status', 'created_at']
            );
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('service_records', 'service_records_app_status_created_idx');
        $this->dropIndexIfExists('files', 'files_app_entity_idx');
        $this->dropIndexIfExists('items', 'items_app_slug_idx');
        $this->dropIndexIfExists('items', 'items_app_type_status_idx');
        $this->dropIndexIfExists('items', 'items_app_entity_status_idx');
        $this->dropIndexIfExists('establishments', 'establishments_app_city_uf_cancelled_idx');
        $this->dropIndexIfExists('establishments', 'establishments_app_user_cancelled_idx');

        if (Schema::hasTable('establishments') && Schema::hasColumn('establishments', 'created_by')) {
            Schema::table('establishments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || ! $this->hasColumns($table, $columns) || $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
