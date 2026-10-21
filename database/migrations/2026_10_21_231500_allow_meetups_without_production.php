<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'production_id')) {
            return;
        }

        // Instalações criadas do zero já possuem esta coluna nullable.
        // Instalações legadas ainda têm NOT NULL, apesar de permitirem kind=community.
        if ($this->productionIsNullable()) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            Schema::table('events', function (Blueprint $table): void {
                $table->unsignedBigInteger('production_id')->nullable()->change();
            });
            return;
        }

        $this->assertExpectedForeignKey();

        // Alteração única preserva o vínculo das produções comerciais e seus índices,
        // sem deixar o banco em estado intermediário sem FK.
        DB::statement(
            'ALTER TABLE `events` '.
            'DROP FOREIGN KEY `events_production_id_foreign`, '.
            'MODIFY COLUMN `production_id` BIGINT UNSIGNED NULL, '.
            'ADD CONSTRAINT `events_production_id_foreign` '.
            'FOREIGN KEY (`production_id`) REFERENCES `establishments` (`id`) '.
            'ON DELETE RESTRICT ON UPDATE RESTRICT'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'production_id')
            || ! $this->productionIsNullable() || DB::getDriverName() !== 'mysql') {
            return;
        }

        // Nunca invalidar dados criados pelos participantes durante um rollback.
        if (DB::table('events')->whereNull('production_id')->exists()) {
            throw new RuntimeException(
                'Rollback bloqueado: há encontros sem produção. Preserve os registros e restaure o esquema pelo backup.'
            );
        }

        $this->assertExpectedForeignKey();
        DB::statement(
            'ALTER TABLE `events` '.
            'DROP FOREIGN KEY `events_production_id_foreign`, '.
            'MODIFY COLUMN `production_id` BIGINT UNSIGNED NOT NULL, '.
            'ADD CONSTRAINT `events_production_id_foreign` '.
            'FOREIGN KEY (`production_id`) REFERENCES `establishments` (`id`) '.
            'ON DELETE RESTRICT ON UPDATE RESTRICT'
        );
    }

    private function productionIsNullable(): bool
    {
        $column = collect(Schema::getColumns('events'))->firstWhere('name', 'production_id');

        if (! $column) {
            throw new RuntimeException('Coluna events.production_id não encontrada.');
        }

        return (bool) ($column['nullable'] ?? false);
    }

    private function assertExpectedForeignKey(): void
    {
        $foreign = collect(Schema::getForeignKeys('events'))
            ->first(fn (array $key): bool => in_array('production_id', $key['columns'] ?? [], true));

        if (($foreign['name'] ?? null) !== 'events_production_id_foreign'
            || ($foreign['foreign_table'] ?? null) !== 'establishments'
            || ($foreign['foreign_columns'] ?? []) !== ['id']
            || strtolower((string) ($foreign['on_delete'] ?? '')) !== 'restrict'
            || strtolower((string) ($foreign['on_update'] ?? '')) !== 'restrict') {
            throw new RuntimeException('FK de produção inesperada; migração interrompida para evitar perda de integridade.');
        }
    }
};
