<?php

namespace App\Observers;

use App\Models\EcosystemPayment;
use App\Services\Payments\FinancialLedgerService;

class EcosystemPaymentObserver
{
    public function __construct(private readonly FinancialLedgerService $ledger) {}

    public function created(EcosystemPayment $payment): void
    {
        $this->ledger->capture($payment);
    }

    public function updated(EcosystemPayment $payment): void
    {
        $this->ledger->capture($payment);
    }
}
