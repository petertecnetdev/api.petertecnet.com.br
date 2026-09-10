<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

final class SubscriptionUpgradeRequired extends RuntimeException
{
    public function __construct(
        public readonly string $entitlement,
        public readonly ?string $planCode = null,
        string $message = 'Seu plano atual não inclui este recurso. Faça upgrade para continuar.',
    ) {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json([
            'success' => false,
            'error' => 'upgrade_required',
            'message' => $this->getMessage(),
            'upgrade' => [
                'entitlement' => $this->entitlement,
                'current' => null,
                'limit' => null,
                'plan_code' => $this->planCode,
            ],
        ], 402);
    }
}
