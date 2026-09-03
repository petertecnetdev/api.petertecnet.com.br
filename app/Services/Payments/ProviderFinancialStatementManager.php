<?php

namespace App\Services\Payments;

use App\Contracts\Payments\ProviderFinancialStatementGateway;
use App\Services\Payments\Statements\MercadoPagoFinancialStatementGateway;
use InvalidArgumentException;

class ProviderFinancialStatementManager
{
    public function __construct(private readonly MercadoPagoFinancialStatementGateway $mercadoPago) {}

    public function for(string $provider): ProviderFinancialStatementGateway
    {
        return match (strtolower(trim($provider))) {
            'mercadopago' => $this->mercadoPago,
            default => throw new InvalidArgumentException("Provedor financeiro não suportado: {$provider}"),
        };
    }
}
