<?php

namespace App\Console\Commands;

use App\Services\Payments\ProviderFinancialStatementService;
use Illuminate\Console\Command;

class SyncProviderFinancialStatements extends Command
{
    protected $signature = 'ecosystem:sync-provider-statements {--provider=mercadopago : Provedor financeiro a sincronizar} {--force : Solicita novamente a janela corrente quando permitido}';
    protected $description = 'Importa extratos oficiais do provedor e reconcilia liberações, saldo disponível e saques bancários.';

    public function handle(ProviderFinancialStatementService $statements): int
    {
        $stats = $statements->maintain((string) $this->option('provider'), (bool) $this->option('force'));

        if (! $stats['configured']) {
            $this->warn('Provedor não configurado para sincronização de extrato.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Extratos: %d solicitados, %d verificados, %d importados, %d linhas processadas, %d erros.',
            $stats['requested'], $stats['checked'], $stats['imported'], $stats['entries'], $stats['errors'],
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
