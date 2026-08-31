<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $metadata) => ($metadata['name'] ?? null) === $index);
    }

    private function foreignExists(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(function (array $metadata) use ($column) {
                $columns = $metadata['columns'] ?? [];
                return in_array($column, $columns, true);
            });
    }

    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            if (!Schema::hasColumn('interactions', 'app_id')) {
                $table->unsignedBigInteger('app_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('interactions', 'route')) {
                $table->string('route', 255)->nullable()->after('interaction_type');
            }
            if (!Schema::hasColumn('interactions', 'method')) {
                $table->string('method', 12)->nullable()->after('route');
            }
            if (!Schema::hasColumn('interactions', 'session_key')) {
                $table->string('session_key', 120)->nullable()->after('method');
            }
        });

        Schema::table('interactions', function (Blueprint $table) {
            if (!$this->indexExists('interactions', 'interactions_user_created_idx')) {
                $table->index(['user_id', 'created_at'], 'interactions_user_created_idx');
            }
            if (!$this->indexExists('interactions', 'interactions_app_created_idx')) {
                $table->index(['app_id', 'created_at'], 'interactions_app_created_idx');
            }
            if (!$this->indexExists('interactions', 'interactions_type_created_idx')) {
                $table->index(['interaction_type', 'created_at'], 'interactions_type_created_idx');
            }
            if (!$this->indexExists('interactions', 'interactions_entity_idx')) {
                $table->index(['entity_type', 'entity_id'], 'interactions_entity_idx');
            }
            if (!$this->foreignExists('interactions', 'app_id')) {
                $table->foreign('app_id')->references('id')->on('applications')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            if ($this->foreignExists('interactions', 'app_id')) {
                $table->dropForeign(['app_id']);
            }
            if ($this->indexExists('interactions', 'interactions_user_created_idx')) {
                $table->dropIndex('interactions_user_created_idx');
            }
            if ($this->indexExists('interactions', 'interactions_app_created_idx')) {
                $table->dropIndex('interactions_app_created_idx');
            }
            if ($this->indexExists('interactions', 'interactions_type_created_idx')) {
                $table->dropIndex('interactions_type_created_idx');
            }
            if ($this->indexExists('interactions', 'interactions_entity_idx')) {
                $table->dropIndex('interactions_entity_idx');
            }
        });

        $columns = collect(['app_id', 'route', 'method', 'session_key'])
            ->filter(fn ($column) => Schema::hasColumn('interactions', $column))
            ->values()
            ->all();

        if ($columns) {
            Schema::table('interactions', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
