<?php

namespace App\Domain\Finance\Contracts;

interface PayoutWebhookInterpreter
{
    /**
     * Normalize a provider webhook into Peter Finance semantics.
     * Return null for events that are irrelevant to payouts.
     */
    public function normalizeWebhook(array $payload): ?array;
}
