<?php

namespace App\Console\Commands;

use App\Services\ApplicationProfileSyncService;
use Illuminate\Console\Command;

class SyncApplicationProfiles extends Command
{
    protected $signature = 'ecosystem:sync-application-profiles {--json : Retorna somente JSON}';

    protected $description = 'Sincroniza perfis funcionais por aplicação e migra vínculos existentes com segurança';

    public function handle(ApplicationProfileSyncService $service): int
    {
        $result = $service->sync();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Perfis funcionais sincronizados.');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Perfis sincronizados', $result['profiles_synced']],
                ['Novos vínculos', $result['assignments_created']],
                ['Aplicações', implode(', ', $result['applications'])],
            ],
        );

        return self::SUCCESS;
    }
}
