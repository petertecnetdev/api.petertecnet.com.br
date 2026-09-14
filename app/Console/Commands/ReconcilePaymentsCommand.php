<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Services\PaymentHealthRecoveryAttributionService;
use App\Domain\Finance\Http\Controllers\PaymentProviderController;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Services\DeliveryEffectService;
use App\Services\EventAudienceService;
use App\Support\ApplicationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'platform:reconcile-payments {order? : ID ou public_id de um pedido específico} {--application= : Slug da aplicação} {--limit=50 : Máximo de pagamentos no modo automático}';
    protected $description = 'Reconcilia pagamentos e reprocessa fulfillment pendente em qualquer aplicação.';

    public function handle(
        PaymentProviderController $controller,
        EventAudienceService $audience,
        DeliveryEffectService $deliveries,
        ApplicationContext $context,
        PaymentHealthRecoveryAttributionService $recoveryAttribution
    ): int
    {
        $startedAt = microtime(true);
        $orderRef = $this->argument('order');
        $applicationSlug = trim((string) $this->option('application'));
        $payments = collect();

        if ($orderRef !== null) {
            $order = CommerceOrder::query()
                ->when($applicationSlug !== '', function ($query) use ($applicationSlug) {
                    $appId = Application::query()->where('slug', $applicationSlug)->value('id');
                    $query->where('app_id', $appId ?: -1);
                })
                ->where(function ($query) use ($orderRef) {
                    $query->where('public_id', (string) $orderRef);
                    if (is_numeric($orderRef)) {
                        $query->orWhere('id', (int) $orderRef);
                    }
                })
                ->first();

            if (! $order) {
                $this->error('Pedido não encontrado.');
                return self::FAILURE;
            }

            $payment = $order->payments()->where('provider', 'mercadopago')->latest('id')->first();
            if (! $payment) {
                $this->error('O pedido não possui pagamento reconciliável.');
                return self::FAILURE;
            }
            $payments = collect([$payment]);
        } else {
            $limit = max(1, min((int) $this->option('limit'), 200));
            $appId = null;
            if ($applicationSlug !== '') {
                $appId = Application::query()->where('slug', $applicationSlug)->where('is_active', true)->value('id');
                if (! $appId) {
                    $this->error('Aplicação não encontrada ou inativa.');
                    return self::FAILURE;
                }
            }

            $pendingDeliveryEffects = DB::table('delivery_effects')
                ->when($appId, fn ($query) => $query->where('app_id', $appId))
                ->where('aggregate_type', 'commerce_order')
                ->where('status', '!=', 'completed')
                ->orderBy('updated_at')
                ->limit($limit)
                ->get(['app_id', 'aggregate_id']);
            $pendingDeliveryKeys = $pendingDeliveryEffects->mapWithKeys(
                fn ($row) => [((int) $row->app_id).':'.((int) $row->aggregate_id) => true]
            );

            $candidates = CommercePayment::query()
                ->when($appId, fn ($query) => $query->where('app_id', $appId))
                ->where('provider', 'mercadopago')
                ->whereNotNull('provider_payment_id')
                ->with('order')
                ->latest('id')
                ->limit($limit * 3)
                ->get();

            if ($pendingDeliveryEffects->isNotEmpty()) {
                $deliveryCandidates = CommercePayment::query()
                    ->when($appId, fn ($query) => $query->where('app_id', $appId))
                    ->where('provider', 'mercadopago')
                    ->whereNotNull('provider_payment_id')
                    ->where(function ($query) use ($pendingDeliveryEffects) {
                        foreach ($pendingDeliveryEffects as $effect) {
                            $query->orWhere(function ($pair) use ($effect) {
                                $pair->where('app_id', (int) $effect->app_id)
                                    ->where('order_id', (int) $effect->aggregate_id);
                            });
                        }
                    })
                    ->with('order')
                    ->get();
                $candidates = $candidates->concat($deliveryCandidates)->unique('id')->values();
            }

            $underfulfilledOrderIds = DB::table('commerce_order_items as coi')
                ->join('commerce_orders as co', function ($join) {
                    $join->on('co.id', '=', 'coi.order_id')
                        ->on('co.app_id', '=', 'coi.app_id');
                })
                ->leftJoin('event_passes as ep', 'ep.commerce_order_item_id', '=', 'coi.id')
                ->when($appId, fn ($query) => $query->where('co.app_id', $appId))
                ->where('co.status', 'paid')
                ->where('coi.type', 'ticket')
                ->groupBy('co.id', 'coi.id', 'coi.quantity')
                ->havingRaw('COUNT(ep.id) < coi.quantity')
                ->orderByRaw('MIN(co.updated_at) ASC')
                ->limit($limit)
                ->pluck('co.id')
                ->unique()
                ->values();

            if ($underfulfilledOrderIds->isNotEmpty()) {
                $fulfillmentCandidates = CommercePayment::query()
                    ->when($appId, fn ($query) => $query->where('app_id', $appId))
                    ->where('provider', 'mercadopago')
                    ->whereNotNull('provider_payment_id')
                    ->whereIn('order_id', $underfulfilledOrderIds)
                    ->with('order')
                    ->latest('id')
                    ->get();
                $candidates = $candidates->concat($fulfillmentCandidates)->unique('id')->values();
            }

            $payments = $candidates
                ->filter(function (CommercePayment $payment) use ($pendingDeliveryKeys) {
                    $order = $payment->order;
                    if (! $order) return false;
                    if ($order->status === 'pending') return true;
                    if ($order->status !== 'paid') return false;
                    if ($this->hasIncompleteTicketFulfillment($order)) return true;
                    if (data_get($order->metadata, 'fulfillment_status') !== 'completed') return true;
                    return $pendingDeliveryKeys->has(((int) $payment->app_id).':'.((int) $order->id));
                })
                ->take($limit)
                ->values();
        }

        if ($payments->isEmpty()) {
            $this->info('Nenhum pagamento precisa de reconciliação.');
            $this->logRunTelemetry($startedAt, $applicationSlug, 0, 0, 0, 0, 0, 0, 0.0, []);
            return self::SUCCESS;
        }

        $failures = 0;
        $recoveredPaidOrders = 0;
        $recoveredFulfillments = 0;
        $recoveredDeliveryRetries = 0;
        $unresolvedTicketFulfillments = 0;
        $recoveredGmv = 0.0;
        $byApplication = [];
        foreach ($payments as $payment) {
            $application = null;
            try {
                $application = Application::query()->whereKey((int) $payment->app_id)->where('is_active', true)->firstOrFail();
                $context->set($application);
                $appSlug = (string) $application->slug;
                $byApplication[$appSlug] ??= [
                    'application' => $appSlug,
                    'selected' => 0,
                    'recovered_paid_orders' => 0,
                    'recovered_fulfillments' => 0,
                    'recovered_delivery_retries' => 0,
                    'unresolved_ticket_fulfillments' => 0,
                    'failures' => 0,
                    'recovered_gmv' => 0.0,
                ];
                $byApplication[$appSlug]['selected']++;

                $order = $payment->order;
                $statusBefore = (string) ($order?->status ?? '');
                $fulfillmentBefore = (string) data_get($order?->metadata, 'fulfillment_status', '');
                $passesIncompleteBefore = $order ? $this->hasIncompleteTicketFulfillment($order) : false;
                $deliveryOnlyRetry = $order
                    && $order->status === 'paid'
                    && data_get($order->metadata, 'fulfillment_status') === 'completed'
                    && ! $passesIncompleteBefore
                    && $deliveries->hasPendingForAggregate((int) $payment->app_id, 'commerce_order', (int) $order->id);

                if ($deliveryOnlyRetry) {
                    $audience->confirmPaidOrder((int) $order->id);
                    $order = $order->fresh(['items', 'event', 'payments']);
                    $recoveredDeliveryRetries++;
                    $byApplication[$appSlug]['recovered_delivery_retries']++;
                } else {
                    $order = $controller->reconcilePaymentId((int) $payment->id);
                }

                $paidRecovered = $statusBefore !== 'paid' && $order->status === 'paid';
                $fulfillmentRecovered = ($fulfillmentBefore !== 'completed' || $passesIncompleteBefore)
                    && data_get($order->metadata, 'fulfillment_status') === 'completed'
                    && ! $this->hasIncompleteTicketFulfillment($order);
                $recoveredOrderGmv = 0.0;

                if ($paidRecovered) {
                    $recoveredPaidOrders++;
                    $recoveredOrderGmv = (float) $order->total;
                    $recoveredGmv += $recoveredOrderGmv;
                    $byApplication[$appSlug]['recovered_paid_orders']++;
                    $byApplication[$appSlug]['recovered_gmv'] += $recoveredOrderGmv;
                }
                if ($fulfillmentRecovered) {
                    $recoveredFulfillments++;
                    $byApplication[$appSlug]['recovered_fulfillments']++;
                }

                $passesIncompleteAfter = $order->status === 'paid'
                    && $this->hasIncompleteTicketFulfillment($order);
                if ($passesIncompleteAfter) {
                    $unresolvedTicketFulfillments++;
                    $byApplication[$appSlug]['unresolved_ticket_fulfillments']++;
                    Log::error('commerce.payment_reconciliation.ticket_fulfillment_unresolved', [
                        'app_id' => (int) $payment->app_id,
                        'application' => $appSlug,
                        'order_id' => (int) $order->id,
                        'order_public_id' => (string) $order->public_id,
                        'payment_id' => (int) $payment->id,
                        'provider_payment_id' => (string) $payment->provider_payment_id,
                        'order_total' => round((float) $order->total, 2),
                        'alert_reason' => 'paid_order_missing_event_passes_after_reconciliation',
                    ]);
                }

                $recoveryAttribution->record(
                    (int) $payment->app_id,
                    $paidRecovered ? 1 : 0,
                    $fulfillmentRecovered ? 1 : 0,
                    $deliveryOnlyRetry ? 1 : 0,
                    $recoveredOrderGmv
                );

                $this->info(sprintf(
                    'OK app=%s pedido=%s status=%s fulfillment=%s pagamento=%s',
                    $application->slug,
                    $order->public_id,
                    $order->status,
                    data_get($order->metadata, 'fulfillment_status', '-'),
                    $payment->provider_payment_id
                ));
            } catch (Throwable $e) {
                $failures++;
                if ($application) {
                    $byApplication[(string) $application->slug]['failures']++;
                }
                report($e);
                $this->error(sprintf('ERRO pagamento=%s: %s', $payment->provider_payment_id, $e->getMessage()));
            } finally {
                $context->clear();
            }
        }

        $this->logRunTelemetry(
            $startedAt,
            $applicationSlug,
            $payments->count(),
            $recoveredPaidOrders,
            $recoveredFulfillments,
            $recoveredDeliveryRetries,
            $unresolvedTicketFulfillments,
            $failures,
            $recoveredGmv,
            $byApplication
        );

        return $failures === 0 && $unresolvedTicketFulfillments === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasIncompleteTicketFulfillment(CommerceOrder $order): bool
    {
        $ticketLines = $order->items()->where('type', 'ticket')->get(['id', 'quantity']);
        if ($ticketLines->isEmpty()) {
            return false;
        }

        $issuedCounts = DB::table('event_passes')
            ->whereIn('commerce_order_item_id', $ticketLines->pluck('id'))
            ->select('commerce_order_item_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('commerce_order_item_id')
            ->pluck('aggregate', 'commerce_order_item_id');

        foreach ($ticketLines as $line) {
            if ((int) ($issuedCounts[$line->id] ?? 0) < (int) $line->quantity) {
                return true;
            }
        }

        return false;
    }

    private function logRunTelemetry(
        float $startedAt,
        string $applicationSlug,
        int $selected,
        int $recoveredPaidOrders,
        int $recoveredFulfillments,
        int $recoveredDeliveryRetries,
        int $unresolvedTicketFulfillments,
        int $failures,
        float $recoveredGmv,
        array $byApplication
    ): void {
        $applicationBreakdown = array_values(array_map(function (array $stats): array {
            $stats['recovered_gmv'] = round((float) $stats['recovered_gmv'], 2);
            return $stats;
        }, $byApplication));

        usort($applicationBreakdown, fn (array $a, array $b) => $a['application'] <=> $b['application']);

        $context = [
            'application' => $applicationSlug !== '' ? $applicationSlug : 'all',
            'selected' => $selected,
            'recovered_paid_orders' => $recoveredPaidOrders,
            'recovered_fulfillments' => $recoveredFulfillments,
            'recovered_delivery_retries' => $recoveredDeliveryRetries,
            'unresolved_ticket_fulfillments' => $unresolvedTicketFulfillments,
            'failures' => $failures,
            'recovered_gmv' => round($recoveredGmv, 2),
            'by_application' => $applicationBreakdown,
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        ];

        Log::info('commerce.payment_reconciliation.completed', $context);

        if ($unresolvedTicketFulfillments > 0) {
            Log::error('commerce.payment_reconciliation.ticket_fulfillment_alert', $context + [
                'alert_reason' => 'paid_orders_remain_without_complete_event_passes',
            ]);
        }

        if ($failures > 0) {
            Log::error('commerce.payment_reconciliation.failed', $context + [
                'alert_reason' => 'reconciliation_failures',
            ]);
            return;
        }

        if ($recoveredPaidOrders > 0 || $recoveredFulfillments > 0 || $recoveredDeliveryRetries > 0) {
            Log::warning('commerce.payment_reconciliation.recovery_detected', $context + [
                'alert_reason' => 'payment_or_fulfillment_recovered',
            ]);
        }
    }
}
