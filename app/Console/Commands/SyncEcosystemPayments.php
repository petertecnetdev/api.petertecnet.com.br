<?php

namespace App\Console\Commands;

use App\Services\EcosystemPaymentLedgerService;
use App\Services\Payments\PaymentLifecycleService;
use App\Services\Payments\PaymentReconciliationService;
use Illuminate\Console\Command;

class SyncEcosystemPayments extends Command
{
    protected $signature = 'ecosystem:sync-payments {--reconcile=100 : Quantidade máxima de pagamentos a conciliar por execução}';
    protected $description = 'Sincroniza pagamentos, ledger, vencimentos e conciliação financeira do ecossistema.';

    public function handle(
        EcosystemPaymentLedgerService $ledger,
        PaymentLifecycleService $lifecycle,
        PaymentReconciliationService $reconciliation,
    ): int {
        $legacy = $ledger->backfillCutinapp();
        $captured = $ledger->backfillFinancialLedger();
        $expired = $lifecycle->expireStaleOpenPayments();
        $stats = $reconciliation->reconcileBatch((int) $this->option('reconcile'));

        $this->info("Pagamentos legados sincronizados: {$legacy}.");
        $this->info("Pagamentos verificados no ledger: {$captured}.");
        $this->info("Cobranças expiradas: {$expired}.");
        $this->info(sprintf(
            'Conciliação: %d verificados, %d compatíveis, %d corrigidos, %d erros.',
            $stats['checked'],
            $stats['matched'],
            $stats['corrected'],
            $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
