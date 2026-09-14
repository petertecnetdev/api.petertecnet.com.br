<?php

namespace App\Domain\Commerce\Services;

use App\Models\Application;
use App\Models\CommerceOrder;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * @return array{applications:int,eligible:int,attempted:int,completed_orders:int,recovered_orders:int,recovered_passes:int,protected_gmv:float,protected_platform_revenue:float,failed:int,throttled:int}
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
            'completed_orders' => 0,
            'recovered_orders' => 0,
            'recovered_passes' => 0,
            'protected_gmv' => 0.0,
            'protected_platform_revenue' => 0.0,
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

                // Inspect a wider bounded window than the attempt limit so a
                // throttled old order cannot starve newer recoverable orders.
                $snapshot = $this->health->forApplication((int) $application->id, 100, $slaMinutes);

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
                        $this->recordAutomaticAttempt($orderId, 'started');
                        $recovery = $this->recovery->recover($order->fresh(), 'automatic');
                        $recoveredPasses = max(0, (int) ($recovery['recovered_passes'] ?? 0));
                        $missingPasses = max(0, (int) ($recovery['missing_passes'] ?? 0));

                        if ($missingPasses === 0) {
                            $result['completed_orders']++;
                        }
                        if ($missingPasses === 0 && $recoveredPasses > 0) {
                            $result['recovered_orders']++;
                            $result['protected_gmv'] += max(0, (float) ($recovery['protected_gmv'] ?? 0));
                            $result['protected_platform_revenue'] += max(0, (float) ($recovery['protected_platform_revenue'] ?? 0));
                        }
                        $result['recovered_passes'] += $recoveredPasses;
                        $this->recordAutomaticAttempt($orderId, 'completed', $recoveredPasses);
                    } catch (Throwable $e) {
                        $result['failed']++;
                        $this->recordAutomaticAttempt($orderId, 'failed');
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

        $result['protected_gmv'] = round($result['protected_gmv'], 2);
        $result['protected_platform_revenue'] = round($result['protected_platform_revenue'], 2);

        return $result;
    }

    private function recordAutomaticAttempt(int $orderId, string $outcome, int $recoveredPasses = 0): void
    {
        try {
            DB::transaction(function () use ($orderId, $outcome, $recoveredPasses): void {
                $order = CommerceOrder::query()
                    ->where('app_id', $this->context->id())
                    ->lockForUpdate()
                    ->find($orderId);

                if (! $order) {
                    return;
                }

                $metadata = (array) $order->metadata;
                $clock = now();
                $now = $clock->toIso8601String();
                $day = $clock->toDateString();
                $daily = (array) ($metadata['fulfillment_auto_recovery_daily'] ?? []);
                $dailyRow = (array) ($daily[$day] ?? []);

                if ($outcome === 'started') {
                    $metadata['fulfillment_auto_recovery_attempts'] = max(0, (int) ($metadata['fulfillment_auto_recovery_attempts'] ?? 0)) + 1;
                    $metadata['fulfillment_auto_recovery_last_attempt_at'] = $now;
                    $dailyRow['attempts'] = max(0, (int) ($dailyRow['attempts'] ?? 0)) + 1;
                } elseif ($outcome === 'failed') {
                    $metadata['fulfillment_auto_recovery_failures'] = max(0, (int) ($metadata['fulfillment_auto_recovery_failures'] ?? 0)) + 1;
                    $metadata['fulfillment_auto_recovery_last_failed_at'] = $now;
                    $dailyRow['failures'] = max(0, (int) ($dailyRow['failures'] ?? 0)) + 1;
                } elseif ($outcome === 'completed') {
                    $metadata['fulfillment_auto_recovery_completed_attempts'] = max(0, (int) ($metadata['fulfillment_auto_recovery_completed_attempts'] ?? 0)) + 1;
                    $metadata['fulfillment_auto_recovery_last_completed_at'] = $now;
                    $dailyRow['completed_attempts'] = max(0, (int) ($dailyRow['completed_attempts'] ?? 0)) + 1;
                    if ($recoveredPasses > 0) {
                        $metadata['fulfillment_auto_recovery_recovered_passes'] = max(0, (int) ($metadata['fulfillment_auto_recovery_recovered_passes'] ?? 0)) + $recoveredPasses;
                        $dailyRow['recovered_passes'] = max(0, (int) ($dailyRow['recovered_passes'] ?? 0)) + $recoveredPasses;
                    }
                }

                $daily[$day] = $dailyRow;
                ksort($daily);
                $metadata['fulfillment_auto_recovery_daily'] = array_slice($daily, -32, null, true);
                $order->forceFill(['metadata' => $metadata])->save();
            });
        } catch (Throwable $e) {
            Log::warning('Falha ao persistir métricas da recuperação automática de fulfillment.', [
                'app_id' => $this->context->id(),
                'order_id' => $orderId,
                'outcome' => $outcome,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
