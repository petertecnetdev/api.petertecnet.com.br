<?php

namespace App\Domain\Commerce\Support;

use InvalidArgumentException;

final class CommerceTotals
{
    /**
     * @param array<int, array{unit_price:float|int, quantity:int}> $items
     * @return array{subtotal:float, discount:float, total:float}
     */
    public static function calculate(array $items, float|int $discount = 0): array
    {
        $subtotalCents = 0;

        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPriceCents = self::toCents($item['unit_price'] ?? 0);

            if ($quantity < 1 || $unitPriceCents < 0) {
                throw new InvalidArgumentException('Commerce items require a positive quantity and a non-negative unit price.');
            }

            $subtotalCents += $unitPriceCents * $quantity;
        }

        $discountCents = min($subtotalCents, max(0, self::toCents($discount)));
        $totalCents = max(0, $subtotalCents - $discountCents);

        return [
            'subtotal' => self::fromCents($subtotalCents),
            'discount' => self::fromCents($discountCents),
            'total' => self::fromCents($totalCents),
        ];
    }

    private static function toCents(float|int $amount): int
    {
        if (! is_numeric($amount) || $amount < 0) {
            throw new InvalidArgumentException('Commerce money values must be non-negative numbers.');
        }

        return (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private static function fromCents(int $amountCents): float
    {
        return round($amountCents / 100, 2);
    }
}
