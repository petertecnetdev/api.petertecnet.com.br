<?php

namespace Tests\Unit\Domain\Commerce;

use App\Domain\Commerce\Support\CommerceTotals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommerceTotalsTest extends TestCase
{
    public function test_calculates_multi_item_totals_in_cents(): void
    {
        $this->assertSame([
            'subtotal' => 35.5,
            'discount' => 5.5,
            'total' => 30.0,
        ], CommerceTotals::calculate([
            ['unit_price' => 10.25, 'quantity' => 2],
            ['unit_price' => 14.99, 'quantity' => 1],
        ], 5.50));
    }

    public function test_discount_cannot_make_total_negative(): void
    {
        $this->assertSame([
            'subtotal' => 10.0,
            'discount' => 10.0,
            'total' => 0.0,
        ], CommerceTotals::calculate([
            ['unit_price' => 10, 'quantity' => 1],
        ], 99));
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommerceTotals::calculate([
            ['unit_price' => 10, 'quantity' => 0],
        ]);
    }

    public function test_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommerceTotals::calculate([
            ['unit_price' => -1, 'quantity' => 1],
        ]);
    }
}
