<?php

namespace App\Domain\Acquisition\Services;

use App\Support\ApplicationContext;
use Illuminate\Validation\ValidationException;

final class AcquisitionCommissionPolicy
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function maxPercentage(): float
    {
        return round(max(0, min((float) $this->context->option('commerce.platform_fee_percent', 0), 100)), 2);
    }

    public function assertPercentageAllowed(float $percentage, string $field = 'percentage'): void
    {
        $maximum = $this->maxPercentage();

        if ($percentage <= $maximum) {
            return;
        }

        throw ValidationException::withMessages([
            $field => sprintf(
                'A comissão sobre o GMV não pode superar %.2f%%, que é a taxa da plataforma configurada para esta aplicação.',
                $maximum,
            ),
        ]);
    }
}
