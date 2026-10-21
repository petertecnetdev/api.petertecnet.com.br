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

        // MariaDB devolve errno 121 ao remover e recriar uma FK com o mesmo nome
        // no MESMO ALTER. Separe os comandos, preservando o nome e o vínculo.
        $this->alterProductionNullability('NULL');
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
        $this->alterProductionNullability('NOT NULL');
    }

    private function alterProductionNullability(string $nullability): void
    {
        DB::statement(
            'ALTER TABLE `events` '.
            'DROP FOREIGN KEY `events_production_id_foreign`, '.
            'MODIFY COLUMN `production_id` BIGINT UNSIGNED '.$nullability
        );

        try {
            DB::statement(
                'ALTER TABLE `events` ADD CONSTRAINT `events_production_id_foreign` '.
                'FOREIGN KEY (`production_id`) REFERENCES `establishments` (`id`) '.
                'ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
        } catch (\Throwable $exception) {
            // Em caso de erro, o nome da FK permanece disponível para nova
            // tentativa segura sem alterar novamente os dados já existentes.
            throw new RuntimeException(
                'A coluna foi ajustada, mas a FK precisa ser restaurada antes de prosseguir.',
                previous: $exception
            );
        }
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
