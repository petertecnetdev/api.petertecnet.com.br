<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CommercePaymentService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommerceController extends Controller
{
    private const SELLER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommercePaymentService $payments,
    ) {}

    public function catalog(string $slug): JsonResponse
    {
        $establishment = $this->publicEstablishment($slug);
        $items = Item::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'establishment' => $establishment,
            'items' => $items,
            'commerce' => $this->commerceConfig($establishment),
        ]]);
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

        $user = $request->user();

        [$order, $establishment] = DB::transaction(function () use ($data, $user) {
            $establishment = Establishment::query()
                ->whereKey($data['establishment_id'])
                ->where('app_id', $this->context->id())
                ->where('is_cancelled', false)
                ->where('is_published', true)
                ->lockForUpdate()
                ->firstOrFail();

            $config = $this->commerceConfig($establishment);
            abort_unless($config['available'], 422, $config['unavailable_reason'] ?: 'Compras online indisponíveis.');
            abort_unless($config['fulfillment'][$data['fulfillment']] ?? false, 422, 'Modalidade de recebimento indisponível.');
            abort_unless(in_array($data['payment_method'], $config['payment_methods'], true), 422, 'Forma de pagamento indisponível.');

            if ($data['fulfillment'] === 'delivery') {
                abort_if(trim((string) ($data['delivery_address'] ?? '')) === '', 422, 'Informe o endereço de entrega.');
            }

            $requested = collect($data['items'])
                ->groupBy('item_id')
                ->map(fn ($rows) => $rows->sum('quantity'));

            $catalog = Item::query()
                ->whereIn('id', $requested->keys()->map(fn ($id) => (int) $id))
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->where('status', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless(
                $requested->keys()->every(fn ($id) => $catalog->has((int) $id)),
                422,
                'Há item indisponível na compra.'
            );

            $subtotal = 0.0;
            foreach ($requested as $itemId => $quantity) {
                $item = $catalog->get((int) $itemId);
                $subtotal += (float) $item->price * (int) $quantity;

                $stock = $item->stock;
                if ($stock !== null && (int) $stock > 0) {
                    abort_if((int) $stock < (int) $quantity, 422, "Estoque insuficiente para {$item->name}.");
                }
            }

            $minimum = (float) ($establishment->minimum_order ?? 0);
            abort_if(
                $subtotal < $minimum,
                422,
                'Compra mínima: R$ ' . number_format($minimum, 2, ',', '.')
            );

            $deliveryFee = $data['fulfillment'] === 'delivery'
                ? (float) ($establishment->delivery_fee ?? 0)
                : 0.0;

            $order = Order::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'app_id' => $this->context->id(),
                'entity_name' => 'establishment',
                'entity_id' => $establishment->id,
                'order_number' => Order::nextOrderNumber($this->context->id()),
                'order_datetime' => now(),
                'created_by' => $user->id,
                'client_id' => $user->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $user->email,
                'access_code' => Order::generateAccessCode(),
                'origin' => 'online',
                'fulfillment' => $data['fulfillment'],
                'fulfillment_status' => 'awaiting_payment',
                'payment_status' => 'pending',
                'payment_method' => $data['payment_method'],
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'delivery_address' => $data['fulfillment'] === 'delivery'
                    ? trim((string) $data['delivery_address'])
                    : null,
                'total_price' => $subtotal + $deliveryFee,
                'status' => 'pending',
                'status_updated_at' => now(),
                'notes' => $data['notes'] ?? null,
                'type' => 'commerce',
            ]);

            foreach ($requested as $itemId => $quantity) {
                $item = $catalog->get((int) $itemId);
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'quantity' => (int) $quantity,
                    'unit_price' => (float) $item->price,
                    'subtotal' => (float) $item->price * (int) $quantity,
                ]);

                if ($item->stock !== null && (int) $item->stock > 0) {
                    Item::whereKey($item->id)->decrement('stock', (int) $quantity);
                }
            }

            return [$order->fresh(['items.item']), $establishment];
        }, 3);

        $payment = $this->payments->create(
            $order,
            $establishment,
            $user,
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
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->firstOrFail();

        abort_unless(
            in_array($data['payment_method'], $this->commerceConfig($establishment)['payment_methods'], true),
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

        return response()->json(['success' => true, 'data' => [
            'order' => $this->serializeOrder($order->fresh(['items.item'])),
            'payment' => $payment,
        ]]);
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

    public function mercadoPagoWebhook(Request $request): JsonResponse
    {
        $this->payments->handleMercadoPagoWebhook($request);
        return response()->json(['success' => true]);
    }

    private function serializeOrder(Order $order, bool $seller = false): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->first();

        $claim = $order->payment_status === 'paid'
            && ! in_array($order->fulfillment_status, ['fulfilled', 'delivered', 'blocked'], true)
            ? ['token' => $this->claimToken($order), 'public_id' => $order->public_id]
            : null;

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
                'id', 'name', 'fantasy', 'slug', 'logo', 'address', 'city', 'uf', 'phone',
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

    private function claimToken(Order $order): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$order->public_id, $order->id, $order->app_id, $order->client_id]),
            (string) config('app.key')
        );
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
            ->whereKey($establishmentId)
            ->where('app_id', $this->context->id())
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
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->firstOrFail();
    }

    private function commerceConfig(Establishment $establishment): array
    {
        $providerReady = trim((string) config('services.mercadopago.access_token')) !== '';
        $configured = $this->json(
            $establishment->payment_methods,
            $providerReady ? ['pix', 'card'] : []
        );
        $paymentMethods = array_values(array_intersect($configured, ['pix', 'card']));

        return [
            'available' => (bool) ($establishment->ordering_enabled ?? true)
                && (bool) ($establishment->accepting_orders ?? true)
                && $providerReady,
            'unavailable_reason' => ! $providerReady
                ? 'Pagamento online ainda não foi configurado.'
                : null,
            'payment_methods' => $paymentMethods ?: ($providerReady ? ['pix', 'card'] : []),
            'fulfillment' => [
                'pickup' => (bool) ($establishment->pickup_enabled ?? true),
                'delivery' => (bool) ($establishment->delivery_enabled ?? true),
            ],
            'delivery_fee' => (float) ($establishment->delivery_fee ?? 0),
            'minimum_order' => (float) ($establishment->minimum_order ?? 0),
        ];
    }

    private function json($value, array $fallback): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return $fallback;

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}
