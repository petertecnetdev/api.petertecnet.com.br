<?php

namespace App\Domain\Commerce\Support;

use InvalidArgumentException;

final class CommerceTotals
{
    /**
     * @param list<array{unit_price: int|float, quantity: int|float}> $items
     * @return array{subtotal: float, discount: float, fees: float, total: float}
     */
    public static function calculate(array $items, float $discount = 0.0, float $fees = 0.0): array
    {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            if ($quantity <= 0 || $unitPrice < 0) {
                throw new InvalidArgumentException('Commerce items must have a positive quantity and a non-negative unit price.');
            }

            $subtotal += $unitPrice * $quantity;
        }

        $subtotal = round($subtotal, 2);
        $discount = round(min($subtotal, max(0.0, $discount)), 2);
        $fees = round(max(0.0, $fees), 2);
        $total = round(max(0.0, $subtotal - $discount + $fees), 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'fees' => $fees,
            'total' => $total,
        ];
    }
}
