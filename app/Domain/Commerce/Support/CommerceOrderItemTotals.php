<?php

namespace App\Domain\Commerce\Support;

final class CommerceOrderItemTotals
{
    /** @return array{unit_price: float, quantity: int, subtotal: float} */
    public static function normalize(float $unitPrice, int $quantity): array
    {
        $totals = CommerceTotals::calculate([
            ['unit_price' => $unitPrice, 'quantity' => $quantity],
        ]);

        return [
            'unit_price' => round($unitPrice, 2),
            'quantity' => $quantity,
            'subtotal' => $totals['subtotal'],
        ];
    }
}
