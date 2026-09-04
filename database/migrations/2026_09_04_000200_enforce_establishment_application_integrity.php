<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('applications')
            || ! Schema::hasTable('establishments')
            || ! Schema::hasColumn('establishments', 'app_id')) {
            return;
        }

        if ($this->hasApplicationForeignKey()) {
            return;
        }

        Schema::table('establishments', function (Blueprint $table) {
            $table->foreign('app_id', 'establishments_app_id_foreign')
                ->references('id')
                ->on('applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('establishments') || ! $this->hasApplicationForeignKey()) {
            return;
        }

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropForeign('establishments_app_id_foreign');
        });
    }

    private function hasApplicationForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('establishments') as $foreignKey) {
            $columns = array_map('strtolower', $foreignKey['columns'] ?? []);
            $foreignTable = strtolower((string) ($foreignKey['foreign_table'] ?? $foreignKey['foreignTable'] ?? ''));

            if (in_array('app_id', $columns, true) && $foreignTable === 'applications') {
                return true;
            }
        }

        return false;
    }
};
