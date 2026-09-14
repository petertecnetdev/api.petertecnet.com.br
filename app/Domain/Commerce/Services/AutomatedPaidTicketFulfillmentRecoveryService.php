<?php

namespace App\Domain\Commerce\Services;

use App\Models\Application;
use App\Models\CommerceOrder;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomatedPaidTicketFulfillmentRecoveryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaidTicketFulfillmentHealthService $health,
        private readonly PaidTicketFulfillmentRecoveryService $recovery,
    ) {
    }

    /**
     * Safely retry fulfillment for already-paid ticket orders that remain
     * incomplete beyond the operational SLA.
     *
     * Recovery never creates a payment. PaidTicketFulfillmentRecoveryService
     * revalidates the existing provider payment and issues only missing passes.
     * A short cache throttle prevents repeated provider lookups for a stubborn
     * order while still allowing automatic recovery without human intervention.
     *
     * @return array{applications:int,eligible:int,attempted:int,recovered_orders:int,recovered_passes:int,failed:int,throttled:int}
     */
    public function run(int $limit = 25, int $slaMinutes = 10, int $retryAfterMinutes = 30): array
    {
        $limit = max(1, min($limit, 100));
        $slaMinutes = max(1, min($slaMinutes, 1440));
        $retryAfterMinutes = max(5, min($retryAfterMinutes, 1440));

        $result = [
            'applications' => 0,
            'eligible' => 0,
            'attempted' => 0,
            'recovered_orders' => 0,
            'recovered_passes' => 0,
            'failed' => 0,
            'throttled' => 0,
        ];

        $applications = Application::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('operational_status')
                    ->orWhereNotIn('operational_status', ['maintenance', 'down']);
            })
            ->orderBy('id')
            ->get();

        try {
            foreach ($applications as $application) {
                if ($result['attempted'] >= $limit) {
                    break;
                }

                $result['applications']++;
                $this->context->set($application);

                $remaining = max(1, $limit - $result['attempted']);
                $snapshot = $this->health->forApplication((int) $application->id, min(100, $remaining), $slaMinutes);

                foreach ((array) ($snapshot['orders'] ?? []) as $candidate) {
                    if ($result['attempted'] >= $limit) {
                        break 2;
                    }
                    if (! (bool) ($candidate['sla_breached'] ?? false)) {
                        continue;
                    }

                    $result['eligible']++;
                    $orderId = (int) ($candidate['id'] ?? 0);
                    if ($orderId <= 0) {
                        continue;
                    }

                    $throttleKey = 'commerce:paid-ticket-fulfillment:auto:'.$orderId;
                    if (! Cache::add($throttleKey, now()->toIso8601String(), now()->addMinutes($retryAfterMinutes))) {
                        $result['throttled']++;
                        continue;
                    }

                    $result['attempted']++;

                    try {
                        $order = CommerceOrder::query()
                            ->where('app_id', $application->id)
                            ->findOrFail($orderId);
                        $recovery = $this->recovery->recover($order);
                        $recoveredPasses = max(0, (int) ($recovery['recovered_passes'] ?? 0));

                        if ((int) ($recovery['missing_passes'] ?? 0) === 0) {
                            $result['recovered_orders']++;
                        }
                        $result['recovered_passes'] += $recoveredPasses;
                    } catch (Throwable $e) {
                        $result['failed']++;
                        Log::error('Falha na recuperação automática de fulfillment de ingresso pago.', [
                            'app_id' => (int) $application->id,
                            'order_id' => $orderId,
                            'order_public_id' => $candidate['public_id'] ?? null,
                            'missing_passes' => (int) ($candidate['missing_passes'] ?? 0),
                            'age_minutes' => $candidate['age_minutes'] ?? null,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } finally {
            $this->context->clear();
        }

        return $result;
    }
}
