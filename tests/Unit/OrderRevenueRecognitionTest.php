<?php

namespace Tests\Unit;

use App\Domain\Commerce\Support\OrderRevenueRecognition;
use App\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderRevenueRecognitionTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_it_recognizes_only_collected_or_completed_offline_revenue(
        string $paymentStatus,
        string $paymentMethod,
        string $status,
        bool $expected,
    ): void {
        $order = new Order();
        $order->forceFill([
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'status' => $status,
        ]);

        $this->assertSame($expected, OrderRevenueRecognition::isRecognized($order));
    }

    public static function cases(): array
    {
        return [
            'paid pix' => ['paid', 'pix', 'pending', true],
            'pending pix' => ['pending', 'pix', 'pending', false],
            'failed pix even if completed' => ['failed', 'pix', 'completed', false],
            'completed cash' => ['pending', 'cash', 'completed', true],
            'cash still preparing' => ['pending', 'cash', 'preparing', false],
            'completed card on delivery' => ['pending', 'card_on_delivery', 'completed', true],
            'refunded card on delivery' => ['refunded', 'card_on_delivery', 'completed', false],
        ];
    }
}
