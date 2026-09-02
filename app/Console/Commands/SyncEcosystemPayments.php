<?php

namespace App\Console\Commands;

use App\Services\EcosystemPaymentLedgerService;
use Illuminate\Console\Command;

class SyncEcosystemPayments extends Command
{
    protected $signature = 'ecosystem:sync-payments';
    protected $description = 'Sincroniza pagamentos das aplicações com o ledger financeiro central.';

    public function handle(EcosystemPaymentLedgerService $ledger): int
    {
        $count = $ledger->backfillCutinapp();
        $this->info("{$count} pagamentos Cutinapp verificados no ledger central.");
        return self::SUCCESS;
    }
}
