<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Services\Commerce\CommerceCheckoutService;
use App\Services\Commerce\CommerceConfigurationService;
use App\Services\Commerce\CommerceFulfillmentService;
use App\Services\Commerce\CommercePaymentService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    private const SELLER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceCheckoutService $checkout,
        private readonly CommerceConfigurationService $configuration,
        private readonly CommercePaymentService $payments,
        private readonly CommerceFulfillmentService $fulfillment,
    ) {}

    public function catalog(string $slug): JsonResponse
    {
        $establishment = $this->publicEstablishment($slug);

        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'establishment' => $establishment,
                'items' => $items,
                'commerce' => $this->configuration->forEstablishment($establishment),
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id' => ['required', 'integer'],
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])],
            'payment_method' => ['required', Rule::in(['pix', 'card'])],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        [$order, $establishment] = $this->checkout->create($request->user(), $data);

        $payment = $this->payments->create(
            $order,
            $establishment,
            $request->user(),
            $data['payment_method']
        );

        return response()->json([
            'success' => true,
            'message' => 'Compra criada com sucesso.',
            'data' => [
                'order' => $this->serializeOrder($order->fresh(['items.item'])),
                'payment' => $payment,
            ],
        ], 201);
    }

    public function retryPayment(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['pix', 'card'])],
        ]);

        $order = $this->buyerOrder($request, $publicId);
        abort_if($order->payment_status === 'paid', 422, 'Esta compra já está paga.');

        $establishment = Establishment::query()
            ->forApplication($this->context->id())
            ->whereKey($order->entity_id)
            ->firstOrFail();

        $config = $this->configuration->forEstablishment($establishment);
        abort_unless(
            in_array($data['payment_method'], $config['payment_methods'], true),
            422,
            'Forma de pagamento indisponível.'
        );

        $order->forceFill([
            'payment_method' => $data['payment_method'],
            'payment_status' => 'pending',
        ])->save();

        $payment = $this->payments->create(
            $order,
            $establishment,
            $request->user(),
            $data['payment_method']
        );

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $this->serializeOrder($order->fresh(['items.item'])),
                'payment' => $payment,
            ],
        ]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('client_id', $request->user()->id)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrder($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serializeOrder($this->buyerOrder($request, $publicId)),
        ]);
    }

    public function payment(Request $request, string $publicId): JsonResponse
    {
        $order = $this->buyerOrder($request, $publicId);

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->latest('id')
            ->first();

        if ($payment && $payment->status === 'pending') {
            try {
                $this->payments->sync($payment);
                $payment->refresh();
                $order->refresh();
            } catch (\Throwable) {
                // Polling should remain available during temporary provider outages.
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => $payment ? $this->payments->serialize($payment) : null,
                'order' => $this->serializeOrder($order->fresh(['items.item'])),
            ],
        ]);
    }

    public function establishmentOrders(Request $request, int $establishment): JsonResponse
    {
        $this->manageable($request, $establishment);

        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 50), 1), 100));

        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrder($order, true));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function updateStatus(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::SELLER_STATUSES)],
        ]);

        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('type', 'commerce')
            ->firstOrFail();

        $this->manageable($request, (int) $order->entity_id);

        abort_if(
            $data['status'] === 'completed' && $order->payment_status !== 'paid',
            422,
            'A compra precisa estar paga antes da conclusão.'
        );

        $order->forceFill([
            'status' => $data['status'],
            'status_updated_at' => now(),
            'attended_at' => $data['status'] === 'completed' ? now() : $order->attended_at,
        ])->save();

        return response()->json([
            'success' => true,
            'data' => $this->serializeOrder($order->fresh(['items.item']), true),
        ]);
    }

    /**
     * Temporary compatibility endpoint. New clients use CommerceFulfillmentController.
     */
    public function verifyFulfillment(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:128', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:32', 'required_without:token'],
        ]);

        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();

        $this->manageable($request, (int) $order->entity_id);
        $verified = $this->fulfillment->verify(
            $order,
            $data['token'] ?? null,
            $data['code'] ?? null,
            $request->user(),
            $this->fulfillment->requestContext($request, ['compatibility_endpoint' => true])
        );

        return response()->json([
            'success' => true,
            'message' => 'Compra validada e pronta para recebimento.',
            'data' => $this->serializeOrder($verified, true),
        ]);
    }

    /**
     * Temporary compatibility method. The public route now points to CommerceFulfillmentController.
     */
    public function redeem(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:128', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:32', 'required_without:token'],
        ]);

        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();

        $this->manageable($request, (int) $order->entity_id);
        $redeemed = $this->fulfillment->redeem(
            $order,
            $data['token'] ?? null,
            $data['code'] ?? null,
            $request->user(),
            $this->fulfillment->requestContext($request, ['compatibility_method' => true])
        );

        return response()->json([
            'success' => true,
            'message' => $redeemed->fulfillment === 'delivery'
                ? 'Entrega confirmada.'
                : 'Retirada confirmada.',
            'data' => $this->serializeOrder($redeemed, true),
        ]);
    }

    public function paymentWebhook(Request $request, string $provider): JsonResponse
    {
        $dataId = (string) ($request->input('data.id') ?: $request->query('data_id') ?: '');

        if ($dataId === '') {
            return response()->json(['success' => true]);
        }

        $this->payments->handleWebhook($provider, $dataId, [
            'x-signature' => $request->header('x-signature'),
            'x-request-id' => $request->header('x-request-id'),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Compatibility endpoint for clients/providers configured before generic webhooks.
     */
    public function mercadoPagoWebhook(Request $request): JsonResponse
    {
        return $this->paymentWebhook($request, 'mercadopago');
    }

    private function serializeOrder(Order $order, bool $seller = false): array
    {
        $establishment = Establishment::query()
            ->forApplication($this->context->id())
            ->whereKey($order->entity_id)
            ->first();

        $claim = $seller ? null : $this->fulfillment->claimPayload($order);

        return [
            'id' => $seller ? $order->id : null,
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfillment' => $order->fulfillment,
            'fulfillment_status' => $order->fulfillment_status,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'total_price' => (float) $order->total_price,
            'delivery_address' => $order->delivery_address,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'notes' => $order->notes,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'fulfilled_at' => optional($order->fulfilled_at)->toIso8601String(),
            'establishment' => $establishment?->only([
                'id',
                'name',
                'fantasy',
                'slug',
                'logo',
                'address',
                'city',
                'uf',
                'phone',
            ]),
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn ($line) => [
                    'item_id' => $line->item_id,
                    'name' => $line->item?->name,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'subtotal' => (float) $line->subtotal,
                ])->values()
                : [],
            'claim' => $claim,
        ];
    }

    private function buyerOrder(Request $request, string $publicId): Order
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('client_id', $request->user()->id)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();
    }

    private function manageable(Request $request, int $establishmentId): Establishment
    {
        $establishment = Establishment::query()
            ->forApplication($this->context->id())
            ->whereKey($establishmentId)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $userId = (int) $request->user()->id;

        $isOwner = (int) $establishment->user_id === $userId
            || (int) $establishment->created_by === $userId;

        $isEmployee = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($isOwner || $isEmployee, 403, 'Você não possui acesso a esta operação.');

        return $establishment;
    }

    private function publicEstablishment(string $slug): Establishment
    {
        return Establishment::query()
            ->forApplication($this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }
}
