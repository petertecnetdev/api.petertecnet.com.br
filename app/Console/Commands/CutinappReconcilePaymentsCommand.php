<?php

namespace App\Console\Commands;

use App\Http\Controllers\CutinappMercadoPagoController;
use App\Models\CutinappOrder;
use App\Models\CutinappPayment;
use Illuminate\Console\Command;
use Throwable;

class CutinappReconcilePaymentsCommand extends Command
{
    protected $signature = 'cutinapp:reconcile-payments {order? : ID ou public_id de um pedido específico} {--limit=50 : Máximo de pagamentos no modo automático}';

    protected $description = 'Reconcilia pagamentos Mercado Pago e reprocessa emissão de ingressos pendentes da Cutinapp';

    public function handle(CutinappMercadoPagoController $controller): int
    {
        $orderRef = $this->argument('order');
        $payments = collect();

        if ($orderRef !== null) {
            $order = CutinappOrder::query()
                ->where('public_id', (string) $orderRef)
                ->when(is_numeric($orderRef), fn ($query) => $query->orWhere('id', (int) $orderRef))
                ->first();

            if (!$order) {
                $this->error('Pedido não encontrado.');
                return self::FAILURE;
            }

            $payment = $order->payments()->where('provider', 'mercadopago')->latest('id')->first();
            if (!$payment) {
                $this->error('O pedido não possui pagamento Mercado Pago.');
                return self::FAILURE;
            }
            $payments = collect([$payment]);
        } else {
            $limit = max(1, min((int) $this->option('limit'), 200));
            $candidates = CutinappPayment::query()
                ->where('provider', 'mercadopago')
                ->whereNotNull('provider_payment_id')
                ->with('order')
                ->latest('id')
                ->limit($limit * 3)
                ->get();

            $payments = $candidates
                ->filter(function (CutinappPayment $payment) {
                    $order = $payment->order;
                    if (!$order) return false;
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
                $order = $controller->reconcilePaymentId((int) $payment->id);
                $this->info(sprintf(
                    'OK pedido=%s status=%s fulfillment=%s pagamento=%s',
                    $order->public_id,
                    $order->status,
                    data_get($order->metadata, 'fulfillment_status', '-'),
                    $payment->provider_payment_id
                ));
            } catch (Throwable $e) {
                $failures++;
                report($e);
                $this->error(sprintf(
                    'ERRO pagamento=%s: %s',
                    $payment->provider_payment_id,
                    $e->getMessage()
                ));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
