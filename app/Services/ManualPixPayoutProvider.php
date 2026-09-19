<?php

namespace App\Services;

use App\Domain\Finance\Contracts\PayoutProvider;
use LogicException;

final class ManualPixPayoutProvider implements PayoutProvider
{
    public function name(): string
    {
        return 'manual_pix';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function availableBalance(): float
    {
        return 0.0;
    }

    public function getTransfer(string $transferId): array
    {
        throw $this->unsupported();
    }

    public function lookupPixKey(string $type, string $key): array
    {
        throw $this->unsupported();
    }

    public function transferPix(
        string $reference,
        float $amount,
        string $key,
        string $keyType,
        string $description
    ): array {
        throw $this->unsupported();
    }

    public function normalizePixKey(string $type, string $key): string
    {
        $type = strtoupper(trim($type));
        $value = trim($key);

        if (in_array($type, ['CPF', 'CNPJ', 'PHONE'], true)) {
            return preg_replace('/\D+/', '', $value) ?? '';
        }

        return $type === 'EMAIL' ? mb_strtolower($value) : $value;
    }

    private function unsupported(): LogicException
    {
        return new LogicException('O modo de repasse manual não executa transferências por adapter.');
    }
}
