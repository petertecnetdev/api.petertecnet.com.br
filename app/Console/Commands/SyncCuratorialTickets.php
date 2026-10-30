<?php

namespace App\Console\Commands;

use App\Services\CuratorialTicketSynchronizer;
use Illuminate\Console\Command;

final class SyncCuratorialTickets extends Command
{
    protected $signature = 'curation:sync-tickets {--apply : Cadastra fichas informativas sem habilitar vendas}';
    protected $description = 'Audita ou cadastra ingressos informativos de eventos curatoriais nao reivindicados da aplicacao.';

    public function handle(CuratorialTicketSynchronizer $sync): int
    {
        $report = $sync->sync((bool) $this->option('apply'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
