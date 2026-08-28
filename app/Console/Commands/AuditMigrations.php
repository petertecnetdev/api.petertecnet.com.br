<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuditMigrations extends Command
{
    protected $signature = 'platform:audit-migrations';

    protected $description = 'Detect migration history drift without changing the database.';

    public function handle(): int
    {
        if (! Schema::hasTable('migrations')) {
            $this->error('A tabela migrations não existe.');
            return self::FAILURE;
        }

        $executed = DB::table('migrations')->pluck('migration')->flip();
        $rows = [];
        $driftCount = 0;

        foreach (File::files(database_path('migrations')) as $file) {
            $migration = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $status = $executed->has($migration) ? 'executed' : 'pending';
            $expectedTable = $this->tableFromCreateMigration($migration);
            $tableExists = $expectedTable ? Schema::hasTable($expectedTable) : null;
            $drift = $status === 'pending' && $expectedTable && $tableExists;

            if ($drift) {
                $driftCount++;
            }

            $rows[] = [
                $migration,
                $status,
                $expectedTable ?: '-',
                $tableExists === null ? '-' : ($tableExists ? 'yes' : 'no'),
                $drift ? 'DRIFT' : '',
            ];
        }

        $this->table(
            ['Migration', 'History', 'Expected table', 'Table exists', 'Alert'],
            $rows
        );

        if ($driftCount > 0) {
            $this->warn("Foram encontradas {$driftCount} migrations pendentes cujas tabelas já existem.");
            $this->warn('Não use migrate:fresh em produção. Reconcilie cada migration preservando os dados.');
            return self::FAILURE;
        }

        $this->info('Nenhum conflito simples entre histórico de migrations e tabelas existentes foi detectado.');
        return self::SUCCESS;
    }

    private function tableFromCreateMigration(string $migration): ?string
    {
        if (! preg_match('/_create_(.+)_table$/', $migration, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
