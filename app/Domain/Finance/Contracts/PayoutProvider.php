<?php

namespace App\Domain\Finance\Contracts;

interface PayoutProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function availableBalance(): float;

    public function lookupPixKey(string $type, string $key): array;

    public function transferPix(
        string $reference,
        float $amount,
        string $key,
        string $keyType,
        string $description
    ): array;

    public function normalizePixKey(string $type, string $key): string;
}
