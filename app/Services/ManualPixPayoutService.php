<?php

namespace App\Services;

use App\Domain\Finance\Contracts\PayoutProvider;
use RuntimeException;

final class ManualPixPayoutService implements PayoutProvider
{
    public function name(): string { return 'manual_pix'; }
    public function isConfigured(): bool { return true; }
    public function availableBalance(): float { return PHP_FLOAT_MAX; }

    public function getTransfer(string $transferId): array
    {
        throw new RuntimeException('Repasses Pix manuais não possuem consulta automática ao provedor.');
    }

    public function lookupPixKey(string $type, string $key): array
    {
        throw new RuntimeException('A validação da chave Pix é realizada no fluxo administrativo manual.');
    }

    public function transferPix(string $reference, float $amount, string $key, string $keyType, string $description): array
    {
        throw new RuntimeException('O modo manual_pix não executa transferências automáticas.');
    }

    public function normalizePixKey(string $type, string $key): string
    {
        $type = strtoupper(trim($type));
        $key = trim($key);

        return match ($type) {
            'CPF', 'CNPJ' => preg_replace('/\D+/', '', $key) ?: '',
            'EMAIL' => filter_var(mb_strtolower($key), FILTER_VALIDATE_EMAIL) ? mb_strtolower($key) : '',
            'PHONE' => (($digits = preg_replace('/\D+/', '', $key) ?: '') && strlen($digits) >= 10 && strlen($digits) <= 13)
                ? ('+' . (str_starts_with($digits, '55') ? $digits : '55' . $digits))
                : '',
            'EVP' => preg_match('/^[0-9a-fA-F-]{32,36}$/', $key) ? mb_strtolower($key) : '',
            default => '',
        };
    }
}
