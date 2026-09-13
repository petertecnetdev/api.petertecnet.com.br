<?php

namespace App\Domain\Commerce\Support;

use App\Models\Order;

final class OrderRevenueRecognition
{
    public static function isRecognized(Order $order): bool
    {
        if ($order->payment_status === 'paid') {
            return true;
        }

        if (in_array($order->payment_status, ['failed', 'refunded'], true)) {
            return false;
        }

        return $order->status === 'completed'
            && in_array($order->payment_method, ['cash', 'card_on_delivery'], true);
    }
}
