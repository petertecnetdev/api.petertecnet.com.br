<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\EventPass;
use App\Models\Order;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ApplicationAdminCommerceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ApplicationAdminService $admin,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
    ) {
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Order::query()
            ->where('app_id', $this->context->id())
            ->with([
                'client:id,first_name,last_name,email',
                'creator:id,first_name,last_name,email',
            ]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('order_number', 'like', '%'.$term.'%')
                    ->orWhere('customer_name', 'like', '%'.$term.'%')
                    ->orWhere('customer_email', 'like', '%'.$term.'%')
                    ->orWhere('customer_phone', 'like', '%'.$term.'%')
                    ->orWhereHas('client', fn ($client) => $client->where('email', 'like', '%'.$term.'%'));
            });
        }

        if (! empty($data['payment_status'])) $query->where('payment_status', $data['payment_status']);
        if (! empty($data['status'])) $query->where('status', $data['status']);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function commerceOrders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:50'],
            'production_id' => ['nullable', 'integer', 'min:1'],
            'event_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->with([
                'user:id,first_name,last_name,email',
                'production:id,app_id,name,slug,user_id',
                'event:id,app_id,production_id,title,slug,start_date',
                'payments:id,app_id,order_id,provider,method,status,provider_payment_id,amount,provider_fee,paid_at,refunded_at,failed_at',
            ]);

        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($builder) use ($term) {
                $builder->where('public_id', 'like', '%'.$term.'%')
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('email', 'like', '%'.$term.'%')
                        ->orWhere('first_name', 'like', '%'.$term.'%')
                        ->orWhere('last_name', 'like', '%'.$term.'%'))
                    ->orWhereHas('event', fn ($event) => $event->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('production', fn ($production) => $production->where('name', 'like', '%'.$term.'%'));
            });
        }
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['production_id'])) $query->where('production_id', (int) $data['production_id']);
        if (! empty($data['event_id'])) $query->where('event_id', (int) $data['event_id']);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $query->latest('id')->paginate((int) ($data['per_page'] ?? 25)),
        ]);
    }

    public function finance(): JsonResponse
    {
        $appId = $this->context->id();
        $legacy = DB::table('orders')->where('app_id', $appId);
        $commerce = DB::table('commerce_orders')->where('app_id', $appId);
        $payments = DB::table('commerce_payments')->where('app_id', $appId);

        $paidCommerce = (clone $commerce)->where('status', 'paid');
        $refundedCommerce = (clone $commerce)->where('status', 'refunded');
        $paidLegacy = (clone $legacy)->whereIn('payment_status', ['paid', 'approved', 'completed']);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => [
                'orders_total' => (clone $legacy)->count() + (clone $commerce)->count(),
                'orders_paid' => (clone $paidLegacy)->count() + (clone $paidCommerce)->count(),
                'gmv' => (float) ((clone $legacy)->sum('total_price') + (clone $commerce)->sum('total')),
                'paid_volume' => (float) ((clone $paidLegacy)->sum('total_price') + (clone $paidCommerce)->sum('total')),
                'pending_volume' => (float) ((clone $legacy)->whereIn('payment_status', ['pending', 'waiting', 'processing'])->sum('total_price') + (clone $commerce)->whereIn('status', ['pending', 'processing'])->sum('total')),
                'refunded_volume' => (float) ((clone $legacy)->whereIn('payment_status', ['refunded', 'chargeback', 'charged_back'])->sum('total_price') + (clone $refundedCommerce)->sum('total')),
                'processor_fees' => (float) (clone $payments)->whereIn('status', ['paid', 'approved'])->sum('provider_fee'),
                'platform_fees' => (float) (clone $paidCommerce)->sum('platform_fee'),
                'producer_net' => (float) (clone $paidCommerce)->sum('producer_net'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function updateOrder(Request $request, int $order): JsonResponse
    {
        $model = Order::query()->where('app_id', $this->context->id())->findOrFail($order);
        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'cancelled_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($request->has('payment_status')) {
            throw ValidationException::withMessages([
                'payment_status' => 'O status financeiro não pode ser alterado manualmente. Use a operação de reembolso do provedor.',
            ]);
        }

        $before = Arr::only($model->toArray(), array_keys($data));
        $model->fill($data)->save();
        $fresh = $model->fresh();

        $this->admin->auditAction($this->context->id(), $request->user(), $model->client, 'admin_legacy_order_updated', [
            'order_id' => $model->id,
            'before' => $before,
            'after' => Arr::only($fresh->toArray(), array_keys($data)),
        ], $this->auditContext($request));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $fresh,
        ]);
    }

    public function refundCommerceOrder(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $model = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->with(['payments', 'user:id,first_name,last_name,email'])
            ->findOrFail($order);

        if ($model->status === 'refunded') {
            return response()->json([
                'success' => true,
                'scope' => 'global_application',
                'message' => 'Este pedido já está reembolsado.',
                'data' => $model,
            ]);
        }

        if ($model->status !== 'paid') {
            throw ValidationException::withMessages(['order' => 'Somente pedidos pagos podem ser reembolsados.']);
        }

        $payment = $model->payments
            ->where('provider', 'mercadopago')
            ->whereNotNull('provider_payment_id')
            ->sortByDesc('id')
            ->first(fn (CommercePayment $candidate) => ! $candidate->refunded_at && in_array($candidate->status, ['paid', 'approved'], true));

        if (! $payment) {
            throw ValidationException::withMessages(['payment' => 'Não foi encontrado pagamento Mercado Pago elegível para reembolso.']);
        }

        $settlementMode = (string) data_get($model->metadata, 'settlement_mode', '');
        if (in_array($settlementMode, ['platform_collection', 'same_account'], true)) {
            $accessToken = trim((string) config('services.mercadopago.access_token'));
            if ($accessToken === '') throw new RuntimeException('Token da plataforma Mercado Pago não configurado.');
        } else {
            [, $accessToken] = $this->accounts->accessTokenForOrganization((int) $model->production_id);
        }

        $remote = $this->mercadoPago->refundPayment(
            $accessToken,
            (string) $payment->provider_payment_id,
            'admin-refund-'.$model->id.'-'.Str::uuid(),
        );

        DB::transaction(function () use ($model, $payment, $remote) {
            $lockedOrder = CommerceOrder::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($model->id);
            $lockedPayment = CommercePayment::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($payment->id);

            $lockedPayment->forceFill([
                'status' => 'refunded',
                'refunded_at' => now(),
                'provider_payload' => array_merge((array) $lockedPayment->provider_payload, ['refund' => $remote]),
            ])->save();

            $lockedOrder->forceFill([
                'status' => 'refunded',
                'cancelled_at' => $lockedOrder->cancelled_at ?: now(),
                'metadata' => array_merge((array) $lockedOrder->metadata, ['refunded_at' => now()->toIso8601String()]),
            ])->save();

            EventPass::query()
                ->where('event_id', $lockedOrder->event_id)
                ->whereHas('orderItem', fn ($items) => $items->where('order_id', $lockedOrder->id))
                ->whereNotIn('status', ['refunded', 'charged_back'])
                ->update(['status' => 'refunded', 'checked_in_at' => null, 'checked_in_by' => null, 'updated_at' => now()]);
        });

        $fresh = $model->fresh(['payments', 'event.production', 'user']);
        $this->admin->auditAction($this->context->id(), $request->user(), $fresh->user, 'admin_commerce_order_refunded', [
            'order_id' => $fresh->id,
            'public_id' => $fresh->public_id,
            'payment_id' => $payment->id,
            'provider_payment_id' => $payment->provider_payment_id,
            'amount' => $payment->amount,
            'reason' => $data['reason'],
            'provider_refund_id' => $remote['id'] ?? null,
        ], $this->auditContext($request));

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'message' => 'Reembolso confirmado pelo Mercado Pago.',
            'data' => $fresh,
        ]);
    }

    private function auditContext(Request $request): array
    {
        return [
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'authority' => $request->attributes->get('admin_authority'),
            'scope' => $request->attributes->get('admin_scope'),
        ];
    }
}
