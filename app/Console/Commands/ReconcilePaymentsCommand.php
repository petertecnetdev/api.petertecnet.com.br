<?php

namespace App\Console\Commands;

use App\Domain\Finance\Http\Controllers\PaymentProviderController;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Support\ApplicationContext;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'platform:reconcile-payments {order? : ID ou public_id de um pedido específico} {--application= : Slug da aplicação} {--limit=50 : Máximo de pagamentos no modo automático}';
    protected $description = 'Reconcilia pagamentos e reprocessa fulfillment pendente em qualquer aplicação.';

    public function handle(PaymentProviderController $controller, ApplicationContext $context): int
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

            $candidates = CommercePayment::query()
                ->when($appId, fn ($query) => $query->where('app_id', $appId))
                ->where('provider', 'mercadopago')
                ->whereNotNull('provider_payment_id')
                ->with('order')
                ->latest('id')
                ->limit($limit * 3)
                ->get();

            $payments = $candidates
                ->filter(function (CommercePayment $payment) {
                    $order = $payment->order;
                    if (! $order) return false;
                    if ($order->status === 'pending') return true;
                    if ($order->status !== 'paid') return false;
                    return data_get($order->metadata, 'fulfillment_status') !== 'completed';
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
                $order = $controller->reconcilePaymentId((int) $payment->id);
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
