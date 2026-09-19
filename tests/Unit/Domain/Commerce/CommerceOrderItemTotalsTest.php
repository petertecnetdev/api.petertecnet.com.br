<?php

namespace Tests\Unit\Domain\Commerce;

use App\Domain\Commerce\Support\CommerceOrderItemTotals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommerceOrderItemTotalsTest extends TestCase
{
    public function test_normalizes_quantity_unit_price_and_subtotal(): void
    {
        $this->assertSame([
            'unit_price' => 12.35,
            'quantity' => 3,
            'subtotal' => 37.05,
        ], CommerceOrderItemTotals::normalize(12.345, 3));
    }

    public function test_rejects_non_positive_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommerceOrderItemTotals::normalize(10, 0);
    }

    public function test_rejects_negative_unit_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CommerceOrderItemTotals::normalize(-1, 1);
    }
}
