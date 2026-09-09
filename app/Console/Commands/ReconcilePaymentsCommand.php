<?php

namespace App\Console\Commands;

use App\Domain\Finance\Http\Controllers\PaymentProviderController;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Services\DeliveryEffectService;
use App\Services\EventAudienceService;
use App\Support\ApplicationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'platform:reconcile-payments {order? : ID ou public_id de um pedido específico} {--application= : Slug da aplicação} {--limit=50 : Máximo de pagamentos no modo automático}';
    protected $description = 'Reconcilia pagamentos e reprocessa fulfillment pendente em qualquer aplicação.';

    public function handle(
        PaymentProviderController $controller,
        EventAudienceService $audience,
        DeliveryEffectService $deliveries,
        ApplicationContext $context
    ): int
    {
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

            $payments = $candidates
                ->filter(function (CommercePayment $payment) use ($pendingDeliveryKeys) {
                    $order = $payment->order;
                    if (! $order) return false;
                    if ($order->status === 'pending') return true;
                    if ($order->status !== 'paid') return false;
                    if (data_get($order->metadata, 'fulfillment_status') !== 'completed') return true;
                    return $pendingDeliveryKeys->has(((int) $payment->app_id).':'.((int) $order->id));
                })
                ->take($limit)
                ->values();
        }

        if ($payments->isEmpty()) {
            $this->info('Nenhum pagamento precisa de reconciliação.');
            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($payments as $payment) {
            try {
                $application = Application::query()->whereKey((int) $payment->app_id)->where('is_active', true)->firstOrFail();
                $context->set($application);

                $order = $payment->order;
                $deliveryOnlyRetry = $order
                    && $order->status === 'paid'
                    && data_get($order->metadata, 'fulfillment_status') === 'completed'
                    && $deliveries->hasPendingForAggregate((int) $payment->app_id, 'commerce_order', (int) $order->id);

                if ($deliveryOnlyRetry) {
                    $audience->confirmPaidOrder((int) $order->id);
                    $order = $order->fresh(['items', 'event', 'payments']);
                } else {
                    $order = $controller->reconcilePaymentId((int) $payment->id);
                }

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
                report($e);
                $this->error(sprintf('ERRO pagamento=%s: %s', $payment->provider_payment_id, $e->getMessage()));
            } finally {
                $context->clear();
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
