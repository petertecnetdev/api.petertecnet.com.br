<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\MercadoPagoService;
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
        private readonly MercadoPagoService $mercadoPago,
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

            $requested = collect($data['items'])->groupBy('item_id')->map(fn ($rows) => $rows->sum('quantity'));
            $catalog = Item::query()
                ->whereIn('id', $requested->keys()->map(fn ($id) => (int) $id))
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->where('status', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless($requested->keys()->every(fn ($id) => $catalog->has((int) $id)), 422, 'Há item indisponível na compra.');

            $subtotal = 0.0;
            foreach ($requested as $itemId => $quantity) {
                $item = $catalog->get((int) $itemId);
                abort_if((float) $item->price <= 0, 422, "O item {$item->name} não está disponível para compra online.");
                $subtotal += (float) $item->price * (int) $quantity;

                $stock = $item->stock;
                if ($stock !== null && (int) $stock > 0) {
                    abort_if((int) $stock < (int) $quantity, 422, "Estoque insuficiente para {$item->name}.");
                }
            }

            $minimum = (float) ($establishment->minimum_order ?? 0);
            abort_if($subtotal < $minimum, 422, 'Compra mínima: R$ ' . number_format($minimum, 2, ',', '.'));

            $deliveryFee = $data['fulfillment'] === 'delivery' ? (float) ($establishment->delivery_fee ?? 0) : 0.0;

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
                'delivery_address' => $data['fulfillment'] === 'delivery' ? trim((string) $data['delivery_address']) : null,
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

        $payment = $this->createPayment($order, $establishment, $user, $data['payment_method']);

        return response()->json(['success' => true, 'message' => 'Compra criada com sucesso.', 'data' => [
            'order' => $this->serializeOrder($order->fresh(['items.item'])),
            'payment' => $payment,
        ]], 201);
    }

    public function retryPayment(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', Rule::in(['pix', 'card'])]]);
        $order = $this->buyerOrder($request, $publicId);
        abort_if($order->payment_status === 'paid', 422, 'Esta compra já está paga.');

        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->firstOrFail();

        abort_unless(in_array($data['payment_method'], $this->commerceConfig($establishment)['payment_methods'], true), 422, 'Forma de pagamento indisponível.');

        $order->forceFill(['payment_method' => $data['payment_method'], 'payment_status' => 'pending'])->save();
        $payment = $this->createPayment($order, $establishment, $request->user(), $data['payment_method']);

        return response()->json(['success' => true, 'data' => ['order' => $this->serializeOrder($order->fresh(['items.item'])), 'payment' => $payment]]);
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
        $order = $this->buyerOrder($request, $publicId);
        return response()->json(['success' => true, 'data' => $this->serializeOrder($order)]);
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
                $this->syncPaymentRecord($payment);
                $payment->refresh();
                $order->refresh();
            } catch (\Throwable) {
                // O polling do cliente não deve falhar por uma indisponibilidade temporária do provedor.
            }
        }

        return response()->json(['success' => true, 'data' => [
            'payment' => $payment ? $this->serializePayment($payment) : null,
            'order' => $this->serializeOrder($order->fresh(['items.item'])),
        ]]);
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
        $data = $request->validate(['status' => ['required', Rule::in(self::SELLER_STATUSES)]]);
        $order = Order::query()->where('app_id', $this->context->id())->where('public_id', $publicId)->where('type', 'commerce')->firstOrFail();
        $this->manageable($request, (int) $order->entity_id);

        abort_if($data['status'] === 'completed' && $order->payment_status !== 'paid', 422, 'A compra precisa estar paga antes da conclusão.');
        $order->forceFill([
            'status' => $data['status'],
            'status_updated_at' => now(),
            'attended_at' => $data['status'] === 'completed' ? now() : $order->attended_at,
        ])->save();

        return response()->json(['success' => true, 'data' => $this->serializeOrder($order->fresh(['items.item']), true)]);
    }

    public function redeem(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        $order = DB::transaction(function () use ($request, $publicId, $data) {
            $order = Order::query()
                ->where('app_id', $this->context->id())
                ->where('public_id', $publicId)
                ->where('type', 'commerce')
                ->lockForUpdate()
                ->firstOrFail();

            $this->manageable($request, (int) $order->entity_id);
            abort_unless($order->payment_status === 'paid', 422, 'Pagamento ainda não confirmado.');
            abort_unless(hash_equals($this->claimToken($order), $data['token']), 403, 'QR Code inválido.');
            abort_if(in_array($order->fulfillment_status, ['fulfilled', 'delivered'], true), 409, 'Este QR Code já foi utilizado.');

            $fulfillmentStatus = $order->fulfillment === 'delivery' ? 'delivered' : 'fulfilled';
            $order->forceFill([
                'fulfillment_status' => $fulfillmentStatus,
                'fulfilled_at' => now(),
                'fulfilled_by' => $request->user()->id,
                'status' => 'completed',
                'status_updated_at' => now(),
                'attended_at' => now(),
            ])->save();

            return $order->fresh(['items.item']);
        }, 3);

        return response()->json(['success' => true, 'message' => $order->fulfillment === 'delivery' ? 'Entrega confirmada.' : 'Retirada confirmada.', 'data' => $this->serializeOrder($order, true)]);
    }

    public function mercadoPagoWebhook(Request $request): JsonResponse
    {
        $dataId = (string) ($request->input('data.id') ?: $request->query('data_id') ?: '');
        if ($dataId === '') return response()->json(['success' => true]);

        abort_unless($this->mercadoPago->validateWebhookSignature(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $dataId,
        ), 401, 'Assinatura de webhook inválida.');

        $token = $this->providerToken();
        $remote = $this->mercadoPago->getPayment($token, $dataId);

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('provider', 'mercadopago')
            ->where(function ($query) use ($remote, $dataId) {
                $query->where('provider_payment_id', (string) ($remote['id'] ?? $dataId));
                if (! empty($remote['external_reference'])) {
                    $query->orWhere('source_reference', (string) $remote['external_reference']);
                }
            })
            ->latest('id')
            ->first();

        if ($payment) $this->applyRemotePayment($payment, $remote);
        return response()->json(['success' => true]);
    }

    private function createPayment(Order $order, Establishment $establishment, $user, string $method): array
    {
        $token = $this->providerToken();
        $publicId = (string) Str::uuid();
        $reference = 'commerce-order-' . $order->public_id . '-' . $publicId;

        $payment = EcosystemPayment::query()->create([
            'public_id' => $publicId,
            'app_id' => $this->context->id(),
            'app_slug' => $this->context->slug(),
            'provider' => 'mercadopago',
            'source_type' => 'order',
            'source_reference' => $reference,
            'source_id' => $order->id,
            'user_id' => $user->id,
            'establishment_id' => $establishment->id,
            'currency' => 'BRL',
            'method' => $method,
            'status' => 'pending',
            'gross_amount' => $order->total_price,
            'platform_fee' => 0,
            'provider_fee' => 0,
            'seller_net' => $order->total_price,
            'metadata' => ['order_public_id' => $order->public_id, 'order_number' => $order->order_number],
        ]);

        try {
            if ($method === 'pix') {
                $remote = $this->mercadoPago->createPayment($token, [
                    'transaction_amount' => (float) $order->total_price,
                    'description' => 'Compra #' . $order->order_number,
                    'payment_method_id' => 'pix',
                    'external_reference' => $reference,
                    'notification_url' => $this->notificationUrl(),
                    'payer' => [
                        'email' => $user->email,
                        'first_name' => $user->first_name ?: $order->customer_name,
                        'last_name' => $user->last_name ?: '',
                    ],
                    'metadata' => ['app_slug' => $this->context->slug(), 'order_public_id' => $order->public_id],
                ], 'commerce-pix-' . $order->public_id . '-' . $payment->public_id);

                $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
                $payment->forceFill([
                    'provider_payment_id' => (string) ($remote['id'] ?? ''),
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'remote_status' => $remote['status'] ?? null,
                        'qr_code' => $transaction['qr_code'] ?? null,
                        'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                        'ticket_url' => $transaction['ticket_url'] ?? null,
                    ]),
                ])->save();
                $order->forceFill(['payment_reference' => $payment->provider_payment_id])->save();

                return $this->serializePayment($payment);
            }

            $preferenceItems = $order->items->map(fn ($line) => [
                'id' => (string) $line->item_id,
                'title' => $line->item?->name ?: 'Item',
                'quantity' => (int) $line->quantity,
                'currency_id' => 'BRL',
                'unit_price' => (float) $line->unit_price,
            ])->values()->all();

            if ((float) $order->delivery_fee > 0) {
                $preferenceItems[] = [
                    'id' => 'delivery-fee',
                    'title' => 'Taxa de entrega',
                    'quantity' => 1,
                    'currency_id' => 'BRL',
                    'unit_price' => (float) $order->delivery_fee,
                ];
            }

            $returnUrl = rtrim((string) $this->context->application()->url, '/') . '/purchase/' . $order->public_id;
            $remote = $this->mercadoPago->createPreference($token, [
                'items' => $preferenceItems,
                'payer' => ['email' => $user->email],
                'external_reference' => $reference,
                'notification_url' => $this->notificationUrl(),
                'back_urls' => ['success' => $returnUrl, 'pending' => $returnUrl, 'failure' => $returnUrl],
                'auto_return' => 'approved',
                'payment_methods' => [
                    'excluded_payment_methods' => [['id' => 'pix'], ['id' => 'account_money']],
                    'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'bank_transfer']],
                ],
                'metadata' => ['app_slug' => $this->context->slug(), 'order_public_id' => $order->public_id],
            ], 'commerce-card-' . $order->public_id . '-' . $payment->public_id);

            $payment->forceFill([
                'metadata' => array_merge($payment->metadata ?? [], [
                    'preference_id' => $remote['id'] ?? null,
                    'checkout_url' => $remote['init_point'] ?? null,
                    'sandbox_checkout_url' => $remote['sandbox_init_point'] ?? null,
                ]),
            ])->save();

            return $this->serializePayment($payment);
        } catch (\Throwable $exception) {
            report($exception);
            $payment->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], ['error' => $exception->getMessage()]),
            ])->save();
            $order->forceFill(['payment_status' => 'failed'])->save();

            return array_merge($this->serializePayment($payment), [
                'retryable' => true,
                'message' => 'Não foi possível iniciar o pagamento. Tente novamente.',
            ]);
        }
    }

    private function syncPaymentRecord(EcosystemPayment $payment): void
    {
        $token = $this->providerToken();
        $remote = $payment->provider_payment_id
            ? $this->mercadoPago->getPayment($token, $payment->provider_payment_id)
            : $this->mercadoPago->findPaymentByExternalReference($token, $payment->source_reference);

        if ($remote) $this->applyRemotePayment($payment, $remote);
    }

    private function applyRemotePayment(EcosystemPayment $payment, array $remote): void
    {
        $mapped = match ((string) ($remote['status'] ?? '')) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };

        DB::transaction(function () use ($payment, $remote, $mapped) {
            $providerFee = (float) collect($remote['fee_details'] ?? [])->sum('amount');
            $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
            $payment->forceFill([
                'provider_payment_id' => (string) ($remote['id'] ?? $payment->provider_payment_id),
                'status' => $mapped,
                'provider_fee' => $providerFee,
                'seller_net' => max(0, (float) $payment->gross_amount - $providerFee - (float) $payment->platform_fee),
                'paid_at' => $mapped === 'paid' ? now() : $payment->paid_at,
                'refunded_at' => $mapped === 'refunded' ? now() : $payment->refunded_at,
                'failed_at' => $mapped === 'failed' ? now() : $payment->failed_at,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'remote_status' => $remote['status'] ?? null,
                    'qr_code' => $transaction['qr_code'] ?? data_get($payment->metadata, 'qr_code'),
                    'qr_code_base64' => $transaction['qr_code_base64'] ?? data_get($payment->metadata, 'qr_code_base64'),
                    'ticket_url' => $transaction['ticket_url'] ?? data_get($payment->metadata, 'ticket_url'),
                ]),
            ])->save();

            if ($payment->source_type === 'order' && $payment->source_id) {
                $order = Order::query()->whereKey($payment->source_id)->where('app_id', $this->context->id())->lockForUpdate()->first();
                if ($order) {
                    $order->forceFill([
                        'payment_status' => $mapped,
                        'payment_reference' => $payment->provider_payment_id,
                        'fulfillment_status' => $mapped === 'paid' ? 'available' : ($mapped === 'refunded' ? 'blocked' : $order->fulfillment_status),
                        'status' => $mapped === 'paid' && $order->status === 'pending' ? 'confirmed' : $order->status,
                        'status_updated_at' => now(),
                    ])->save();
                }
            }
        });
    }

    private function serializePayment(EcosystemPayment $payment): array
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return [
            'public_id' => $payment->public_id,
            'provider' => $payment->provider,
            'method' => $payment->method,
            'status' => $payment->status,
            'amount' => (float) $payment->gross_amount,
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
            'qr_code' => $metadata['qr_code'] ?? null,
            'qr_code_base64' => $metadata['qr_code_base64'] ?? null,
            'ticket_url' => $metadata['ticket_url'] ?? null,
            'checkout_url' => $metadata['checkout_url'] ?? null,
            'sandbox_checkout_url' => $metadata['sandbox_checkout_url'] ?? null,
        ];
    }

    private function serializeOrder(Order $order, bool $seller = false): array
    {
        $establishment = Establishment::query()->whereKey($order->entity_id)->where('app_id', $this->context->id())->first();
        $claim = ! $seller
            && $order->payment_status === 'paid'
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
            'establishment' => $establishment?->only(['id', 'name', 'fantasy', 'slug', 'logo', 'address', 'city', 'uf', 'phone']),
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
            ])->values() : [],
            'claim' => $claim,
        ];
    }

    private function claimToken(Order $order): string
    {
        $secret = (string) config('app.key');
        return hash_hmac('sha256', implode('|', [$order->public_id, $order->id, $order->app_id, $order->client_id]), $secret);
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
        $isOwner = (int) $establishment->user_id === $userId;
        $isEmployee = Employer::query()->where('establishment_id', $establishment->id)->where('user_id', $userId)->exists();
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
        $configured = $this->json($establishment->payment_methods, $providerReady ? ['pix', 'card'] : []);
        $paymentMethods = array_values(array_intersect($configured, ['pix', 'card']));

        return [
            'available' => (bool) ($establishment->ordering_enabled ?? true) && (bool) ($establishment->accepting_orders ?? true) && $providerReady,
            'unavailable_reason' => ! $providerReady ? 'Pagamento online ainda não foi configurado.' : null,
            'payment_methods' => $paymentMethods ?: ($providerReady ? ['pix', 'card'] : []),
            'fulfillment' => [
                'pickup' => (bool) ($establishment->pickup_enabled ?? true),
                'delivery' => (bool) ($establishment->delivery_enabled ?? true),
            ],
            'delivery_fee' => (float) ($establishment->delivery_fee ?? 0),
            'minimum_order' => (float) ($establishment->minimum_order ?? 0),
        ];
    }

    private function notificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/apps/' . rawurlencode($this->context->slug()) . '/commerce/payments/mercadopago/webhook';
    }

    private function providerToken(): string
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Mercado Pago não configurado.');
        return $token;
    }

    private function json($value, array $fallback): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}
