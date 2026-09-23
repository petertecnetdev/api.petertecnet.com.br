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

    public function up(): void
    {
        $indexes = [
            'interactions_user_type_created_idx' => ['user_id', 'interaction_type', 'created_at'],
            'interactions_user_app_created_idx' => ['user_id', 'app_id', 'created_at'],
            'interactions_user_outcome_idx' => ['user_id', 'outcome'],
            'interactions_user_severity_idx' => ['user_id', 'severity'],
            'interactions_user_environment_idx' => ['user_id', 'environment'],
            'interactions_user_entity_type_idx' => ['user_id', 'entity_type'],
        ];

        foreach ($indexes as $name => $columns) {
            if ($this->indexExists('interactions', $name)) {
                continue;
            }

            Schema::table('interactions', function (Blueprint $table) use ($name, $columns) {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        $indexes = [
            'interactions_user_type_created_idx',
            'interactions_user_app_created_idx',
            'interactions_user_outcome_idx',
            'interactions_user_severity_idx',
            'interactions_user_environment_idx',
            'interactions_user_entity_type_idx',
        ];

        foreach ($indexes as $name) {
            if (! $this->indexExists('interactions', $name)) {
                continue;
            }

            Schema::table('interactions', function (Blueprint $table) use ($name) {
                $table->dropIndex($name);
            });
        }
    }
};
