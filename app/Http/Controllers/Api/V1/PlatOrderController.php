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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatOrderController extends Controller
{
    private const STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
    ) {
    }

    public function ordering(string $slug): JsonResponse
    {
        $establishment = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->firstOrFail();

        $items = Item::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'establishment' => $this->publicEstablishment($establishment),
                'items' => $items,
                'ordering' => $this->orderingConfig($establishment),
            ],
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id' => ['required', 'integer'],
            'fulfillment' => ['required', Rule::in(['delivery', 'pickup', 'dine-in'])],
            'payment_method' => ['required', Rule::in(['pix', 'cash', 'card_on_delivery'])],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.additions' => ['nullable', 'array', 'max:30'],
            'items.*.additions.*' => ['integer'],
            'items.*.removals' => ['nullable', 'array', 'max:30'],
            'items.*.removals.*' => ['integer'],
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use ($data, $user) {
            $establishment = Establishment::query()
                ->whereKey($data['establishment_id'])
                ->where('app_id', $this->context->id())
                ->where('is_cancelled', false)
                ->where('is_published', true)
                ->lockForUpdate()
                ->firstOrFail();

            $config = $this->orderingConfig($establishment);
            abort_unless($config['available'], 422, $config['unavailable_reason'] ?: 'O restaurante não está recebendo pedidos agora.');
            abort_unless($config['fulfillment'][$data['fulfillment']] ?? false, 422, 'Esta modalidade de atendimento não está disponível.');
            abort_unless(in_array($data['payment_method'], $config['payment_methods'], true), 422, 'Forma de pagamento indisponível.');

            if ($data['fulfillment'] === 'delivery') {
                abort_if(empty(trim((string) ($data['delivery_address'] ?? ''))), 422, 'Informe o endereço de entrega.');
            }

            $primaryIds = collect($data['items'])->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values();
            $modifierIds = collect($data['items'])
                ->flatMap(fn ($row) => array_merge($row['additions'] ?? [], $row['removals'] ?? []))
                ->map(fn ($id) => (int) $id)->unique()->values();
            $allIds = $primaryIds->merge($modifierIds)->unique()->values();

            $catalog = Item::query()
                ->whereIn('id', $allIds)
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->where('status', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless($primaryIds->every(fn ($id) => $catalog->has($id)), 422, 'Um ou mais itens não estão disponíveis neste restaurante.');
            abort_unless($modifierIds->every(fn ($id) => $catalog->has($id)), 422, 'Um ou mais adicionais não estão disponíveis neste restaurante.');

            $subtotal = 0.0;
            $stockDemand = [];
            $prepared = [];

            foreach ($data['items'] as $row) {
                $item = $catalog->get((int) $row['item_id']);
                $quantity = (int) $row['quantity'];
                abort_if($item->type === 'modifier', 422, "{$item->name} não pode ser usado como item principal.");

                $unitPrice = (float) $item->price;
                $lineSubtotal = $unitPrice * $quantity;
                $stockDemand[$item->id] = ($stockDemand[$item->id] ?? 0) + $quantity;

                $additions = [];
                foreach ($row['additions'] ?? [] as $modifierId) {
                    $modifier = $catalog->get((int) $modifierId);
                    abort_unless($modifier, 422, 'Adicional inválido.');
                    $lineSubtotal += (float) $modifier->price * $quantity;
                    $stockDemand[$modifier->id] = ($stockDemand[$modifier->id] ?? 0) + $quantity;
                    $additions[] = $modifier->id;
                }

                $removals = [];
                foreach ($row['removals'] ?? [] as $modifierId) {
                    $modifier = $catalog->get((int) $modifierId);
                    if ($modifier) $removals[] = $modifier->id;
                }

                $subtotal += $lineSubtotal;
                $prepared[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'notes' => $row['notes'] ?? null,
                    'additions' => $additions,
                    'removals' => $removals,
                ];
            }

            foreach ($stockDemand as $itemId => $quantity) {
                $item = $catalog->get((int) $itemId);
                abort_unless($item, 422, 'Item indisponível.');
                abort_if((int) $item->stock < $quantity, 422, "Estoque insuficiente para {$item->name}.");
            }

            $minimumOrder = (float) ($establishment->minimum_order ?? 0);
            abort_if($subtotal < $minimumOrder, 422, 'O pedido mínimo deste restaurante é R$ ' . number_format($minimumOrder, 2, ',', '.'));

            $deliveryFee = $data['fulfillment'] === 'delivery' ? (float) ($establishment->delivery_fee ?? 0) : 0.0;
            $total = $subtotal + $deliveryFee;

            $order = Order::create([
                'app_id' => $this->context->id(),
                'entity_name' => 'establishment',
                'entity_id' => $establishment->id,
                'order_number' => Order::nextOrderNumber($this->context->id()),
                'order_datetime' => now('America/Sao_Paulo'),
                'created_by' => $user->id,
                'attendant_id' => null,
                'client_id' => $user->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $user->email,
                'access_code' => Order::generateAccessCode(),
                'origin' => 'Online',
                'fulfillment' => $data['fulfillment'],
                'payment_status' => 'pending',
                'payment_method' => $data['payment_method'],
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'delivery_address' => $data['fulfillment'] === 'delivery' ? trim((string) $data['delivery_address']) : null,
                'total_price' => $total,
                'status' => 'pending',
                'status_updated_at' => now(),
                'notes' => $data['notes'] ?? null,
                'type' => 'direct',
            ]);

            foreach ($prepared as $row) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $row['item']->id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['subtotal'],
                    'notes' => $row['notes'],
                ]);

                foreach ($row['additions'] as $modifierId) {
                    DB::table('order_item_modifiers')->insert([
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => $modifierId,
                        'type' => 'addition',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                foreach ($row['removals'] as $modifierId) {
                    DB::table('order_item_modifiers')->insert([
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => $modifierId,
                        'type' => 'removal',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            foreach ($stockDemand as $itemId => $quantity) {
                Item::whereKey($itemId)->decrement('stock', $quantity);
            }

            return [$order->fresh(['items']), $establishment];
        }, 3);

        [$order, $establishment] = $result;
        $payment = null;

        if ($data['payment_method'] === 'pix') {
            $payment = $this->createPixPayment($order, $establishment, $request->user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Pedido criado com sucesso.',
            'data' => [
                'order' => $this->serializeOrder($order),
                'payment' => $payment,
            ],
        ], 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('client_id', $request->user()->id)
            ->where('entity_name', 'establishment')
            ->with(['items.item'])
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrder($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function myOrder(Request $request, int $order): JsonResponse
    {
        $model = Order::query()
            ->whereKey($order)
            ->where('app_id', $this->context->id())
            ->where('client_id', $request->user()->id)
            ->where('entity_name', 'establishment')
            ->with(['items.item'])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->serializeOrder($model)]);
    }

    public function establishmentOrders(Request $request, int $establishment): JsonResponse
    {
        $est = $this->manageableEstablishment($request, $establishment);
        $query = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $est->id)
            ->with(['items.item'])
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($q) => $q->where('customer_name', 'like', $term)->orWhere('order_number', 'like', $term));
        }

        $orders = $query->paginate(min(max((int) $request->query('per_page', 50), 1), 100));
        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrder($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]]);

        $updated = DB::transaction(function () use ($request, $order, $data) {
            $model = Order::query()
                ->whereKey($order)
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'establishment')
                ->with('items')
                ->lockForUpdate()
                ->firstOrFail();

            $this->manageableEstablishment($request, (int) $model->entity_id);
            $previous = (string) $model->status;

            if ($previous === 'cancelled' && $data['status'] !== 'cancelled') {
                abort(422, 'Pedido cancelado não pode ser reaberto automaticamente.');
            }

            if ($data['status'] === 'cancelled' && $previous !== 'cancelled') {
                foreach ($model->items as $line) {
                    if ($line->item_id) Item::whereKey($line->item_id)->increment('stock', (int) $line->quantity);
                    $additionIds = DB::table('order_item_modifiers')
                        ->where('order_item_id', $line->id)
                        ->where('type', 'addition')
                        ->pluck('modifier_id');
                    foreach ($additionIds as $modifierId) {
                        if ($modifierId) Item::whereKey($modifierId)->increment('stock', (int) $line->quantity);
                    }
                }
            }

            $model->status = $data['status'];
            $model->status_updated_at = now();
            if ($data['status'] === 'completed') $model->attended_at = now();
            $model->save();

            return $model->fresh(['items.item']);
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Status do pedido atualizado.',
            'data' => $this->serializeOrder($updated),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $establishments = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->get(['id', 'name', 'fantasy', 'slug', 'logo', 'accepting_orders']);

        $ids = $establishments->pluck('id');
        $start = Carbon::now('America/Sao_Paulo')->startOfDay()->utc();
        $end = Carbon::now('America/Sao_Paulo')->endOfDay()->utc();

        $rows = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'entity_id', 'customer_name', 'total_price']);

        $byEstablishment = $rows->groupBy('entity_id');
        $metrics = $establishments->map(function ($est) use ($byEstablishment) {
            $orders = $byEstablishment->get($est->id, collect());
            $revenue = (float) $orders->sum('total_price');
            return [
                'establishment' => $est,
                'orders' => $orders->count(),
                'revenue' => round($revenue, 2),
                'average_ticket' => $orders->count() ? round($revenue / $orders->count(), 2) : 0,
            ];
        })->values();

        $totalOrders = $rows->count();
        $revenue = (float) $rows->sum('total_price');

        return response()->json(['success' => true, 'data' => [
            'totals' => [
                'orders' => $totalOrders,
                'revenue' => round($revenue, 2),
                'average_ticket' => $totalOrders ? round($revenue / $totalOrders, 2) : 0,
                'establishments' => $establishments->count(),
            ],
            'establishments' => $metrics,
        ]]);
    }

    public function updateOrderingSettings(Request $request, int $establishment): JsonResponse
    {
        $est = $this->manageableEstablishment($request, $establishment);
        $data = $request->validate([
            'ordering_enabled' => ['sometimes', 'boolean'],
            'accepting_orders' => ['sometimes', 'boolean'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'dine_in_enabled' => ['sometimes', 'boolean'],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0', 'max:9999.99'],
            'minimum_order' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'estimated_delivery_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'opening_hours' => ['nullable', 'array'],
            'payment_methods' => ['sometimes', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(['pix', 'cash', 'card_on_delivery'])],
            'pix_key' => ['nullable', 'string', 'max:255'],
        ]);

        $est->forceFill([
            ...$data,
            'opening_hours' => array_key_exists('opening_hours', $data) ? json_encode($data['opening_hours']) : $est->opening_hours,
            'payment_methods' => array_key_exists('payment_methods', $data) ? json_encode(array_values(array_unique($data['payment_methods']))) : $est->payment_methods,
            'updated_by' => $request->user()->id,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Configurações de pedidos atualizadas.',
            'data' => $this->orderingConfig($est->fresh()),
        ]);
    }

    public function mercadoPagoWebhook(Request $request): JsonResponse
    {
        $dataId = (string) ($request->input('data.id') ?: $request->query('data_id') ?: '');
        if ($dataId === '') return response()->json(['success' => true]);

        $valid = $this->mercadoPago->validateWebhookSignature(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $dataId,
        );
        abort_unless($valid, 401, 'Assinatura de webhook inválida.');

        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Mercado Pago não configurado.');
        $remote = $this->mercadoPago->getPayment($token, $dataId);

        $payment = EcosystemPayment::query()
            ->where('app_slug', $this->context->slug())
            ->where('provider', 'mercadopago')
            ->where('provider_payment_id', (string) ($remote['id'] ?? $dataId))
            ->first();

        if (! $payment) return response()->json(['success' => true]);

        $status = (string) ($remote['status'] ?? 'pending');
        $mapped = match ($status) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };

        DB::transaction(function () use ($payment, $mapped, $remote) {
            $payment->status = $mapped;
            $payment->provider_fee = (float) collect($remote['fee_details'] ?? [])->sum('amount');
            $payment->seller_net = max(0, (float) $payment->gross_amount - (float) $payment->provider_fee - (float) $payment->platform_fee);
            if ($mapped === 'paid') $payment->paid_at = now();
            if ($mapped === 'refunded') $payment->refunded_at = now();
            if ($mapped === 'failed') $payment->failed_at = now();
            $payment->metadata = array_merge($payment->metadata ?? [], ['last_webhook_status' => $remote['status'] ?? null]);
            $payment->save();

            if ($payment->source_type === 'order' && $payment->source_id) {
                Order::query()
                    ->whereKey($payment->source_id)
                    ->where('app_id', $this->context->id())
                    ->update([
                        'payment_status' => $mapped,
                        'payment_reference' => $payment->provider_payment_id,
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json(['success' => true]);
    }

    private function createPixPayment(Order $order, Establishment $establishment, $user): ?array
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        if ($token === '') {
            if (! empty($establishment->pix_key)) {
                return [
                    'provider' => 'manual_pix',
                    'status' => 'pending',
                    'pix_key' => $establishment->pix_key,
                    'amount' => (float) $order->total_price,
                ];
            }
            abort(503, 'Pagamento Pix ainda não foi configurado para a Plat.');
        }

        $publicId = (string) Str::uuid();
        $reference = 'plat-order-' . $order->id . '-' . $publicId;
        $payment = EcosystemPayment::create([
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
            'method' => 'pix',
            'status' => 'pending',
            'gross_amount' => $order->total_price,
            'platform_fee' => 0,
            'provider_fee' => 0,
            'seller_net' => $order->total_price,
            'metadata' => ['order_number' => $order->order_number],
        ]);

        try {
            $remote = $this->mercadoPago->createPayment($token, [
                'transaction_amount' => (float) $order->total_price,
                'description' => 'Pedido Plat #' . $order->order_number,
                'payment_method_id' => 'pix',
                'external_reference' => $reference,
                'notification_url' => rtrim((string) config('app.url'), '/') . '/api/v1/apps/' . $this->context->slug() . '/payments/mercadopago/webhook',
                'payer' => [
                    'email' => $user->email,
                    'first_name' => $user->first_name ?: $order->customer_name,
                    'last_name' => $user->last_name ?: '',
                ],
            ], 'plat-order-' . $order->id);

            $payment->provider_payment_id = (string) ($remote['id'] ?? '');
            $payment->metadata = array_merge($payment->metadata ?? [], ['remote_status' => $remote['status'] ?? null]);
            $payment->save();
            $order->forceFill(['payment_reference' => $payment->provider_payment_id])->save();

            $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
            return [
                'provider' => 'mercadopago',
                'public_id' => $payment->public_id,
                'status' => $payment->status,
                'amount' => (float) $order->total_price,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
            ];
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => 'failed', 'failed_at' => now(), 'metadata' => array_merge($payment->metadata ?? [], ['error' => $e->getMessage()])])->save();
            throw $e;
        }
    }

    private function manageableEstablishment(Request $request, int $id): Establishment
    {
        $establishment = Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $userId = (int) $request->user()->id;
        $isOwner = (int) $establishment->user_id === $userId;
        $isEmployer = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($isOwner || $isEmployer, 403, 'Você não possui acesso a esta operação.');
        return $establishment;
    }

    private function orderingConfig(Establishment $establishment): array
    {
        $orderingEnabled = (bool) ($establishment->ordering_enabled ?? true);
        $accepting = (bool) ($establishment->accepting_orders ?? true);
        $open = $this->isOpenNow($establishment);
        $available = $orderingEnabled && $accepting && $open;

        return [
            'available' => $available,
            'open_now' => $open,
            'accepting_orders' => $accepting,
            'ordering_enabled' => $orderingEnabled,
            'unavailable_reason' => ! $orderingEnabled
                ? 'Pedidos online estão desativados.'
                : (! $accepting ? 'O restaurante pausou novos pedidos.' : (! $open ? 'O restaurante está fechado agora.' : null)),
            'fulfillment' => [
                'delivery' => (bool) ($establishment->delivery_enabled ?? true),
                'pickup' => (bool) ($establishment->pickup_enabled ?? true),
                'dine-in' => (bool) ($establishment->dine_in_enabled ?? false),
            ],
            'delivery_fee' => (float) ($establishment->delivery_fee ?? 0),
            'minimum_order' => (float) ($establishment->minimum_order ?? 0),
            'estimated_delivery_minutes' => $establishment->estimated_delivery_minutes ? (int) $establishment->estimated_delivery_minutes : null,
            'opening_hours' => $this->decodeJson($establishment->opening_hours, []),
            'payment_methods' => $this->decodeJson($establishment->payment_methods, ['pix', 'cash', 'card_on_delivery']),
            'pix_configured' => trim((string) config('services.mercadopago.access_token')) !== '' || ! empty($establishment->pix_key),
        ];
    }

    private function isOpenNow(Establishment $establishment): bool
    {
        $hours = $this->decodeJson($establishment->opening_hours, []);
        if ($hours === []) return true;

        $now = Carbon::now('America/Sao_Paulo');
        $keys = [strtolower($now->format('l')), (string) $now->dayOfWeekIso];
        $ranges = null;
        foreach ($keys as $key) {
            if (array_key_exists($key, $hours)) {
                $ranges = $hours[$key];
                break;
            }
        }
        if ($ranges === null) return false;
        if ($ranges === true || $ranges === '24h') return true;
        if (! is_array($ranges)) return false;
        if (isset($ranges['open'], $ranges['close'])) $ranges = [$ranges];

        foreach ($ranges as $range) {
            if (! is_array($range) || empty($range['open']) || empty($range['close'])) continue;
            try {
                $open = Carbon::parse($now->toDateString() . ' ' . $range['open'], 'America/Sao_Paulo');
                $close = Carbon::parse($now->toDateString() . ' ' . $range['close'], 'America/Sao_Paulo');
                if ($close->lte($open)) $close->addDay();
                $candidate = $now->copy();
                if ($candidate->lt($open) && $close->isNextDay()) $candidate->addDay();
                if ($candidate->betweenIncluded($open, $close)) return true;
            } catch (\Throwable) {
                continue;
            }
        }
        return false;
    }

    private function decodeJson($value, array $fallback): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function publicEstablishment(Establishment $establishment): array
    {
        return $establishment->only([
            'id', 'name', 'fantasy', 'slug', 'description', 'logo', 'background', 'address',
            'city', 'uf', 'phone', 'instagram_url', 'location', 'segments',
        ]);
    }

    private function serializeOrder(Order $order): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->first(['id', 'name', 'fantasy', 'slug', 'logo']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status ?: 'pending',
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfillment' => $order->fulfillment,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'total_price' => (float) $order->total_price,
            'delivery_address' => $order->delivery_address,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'notes' => $order->notes,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
            'status_updated_at' => $order->status_updated_at ? Carbon::parse($order->status_updated_at)->toIso8601String() : null,
            'establishment' => $establishment,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
                'notes' => $line->notes ?? null,
            ])->values() : [],
        ];
    }
}
