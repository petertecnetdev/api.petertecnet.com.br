<?php

namespace App\Domain\Commerce\Services;

use Illuminate\Support\Facades\Cache;

final class PaymentHealthRecoveryAttributionService
{
    public function record(
        int $appId,
        int $recoveredPaidOrders,
        int $recoveredFulfillments,
        int $recoveredDeliveryRetries,
        float $recoveredGmv
    ): void {
        if ($recoveredPaidOrders <= 0 && $recoveredFulfillments <= 0 && $recoveredDeliveryRetries <= 0) {
            return;
        }

        $cacheKey = "commerce:payment-health:incident:{$appId}";
        $incident = Cache::get($cacheKey);

        if (! is_array($incident) || ($incident['status'] ?? null) !== 'anomaly') {
            return;
        }

        $incident['recovered_paid_orders'] = (int) ($incident['recovered_paid_orders'] ?? 0) + max(0, $recoveredPaidOrders);
        $incident['recovered_fulfillments'] = (int) ($incident['recovered_fulfillments'] ?? 0) + max(0, $recoveredFulfillments);
        $incident['recovered_delivery_retries'] = (int) ($incident['recovered_delivery_retries'] ?? 0) + max(0, $recoveredDeliveryRetries);
        $incident['recovered_gmv'] = round((float) ($incident['recovered_gmv'] ?? 0) + max(0, $recoveredGmv), 2);
        $incident['last_recovery_at'] = now()->toIso8601String();

        Cache::put($cacheKey, $incident, now()->addDays(7));
    }
}
