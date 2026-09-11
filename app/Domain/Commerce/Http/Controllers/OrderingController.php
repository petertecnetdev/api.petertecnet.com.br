<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Interaction;
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
use Throwable;

class OrderingController extends Controller
{
    private const STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $paymentProvider,
    ) {}

    public function ordering(string $slug): JsonResponse
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

        return response()->json([
            'success' => true,
            'data' => [
                'establishment' => $establishment->only([
                    'id', 'name', 'fantasy', 'slug', 'description', 'logo', 'background',
                    'address', 'city', 'uf', 'phone', 'instagram_url', 'location', 'segments',
                ]),
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
            'acquisition_attribution' => ['nullable', 'array:utm_source,utm_medium,utm_campaign,utm_content,utm_term,acquisition_source,acquisition_landing,acquisition_captured_at'],
            'acquisition_attribution.utm_source' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.utm_medium' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.utm_campaign' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.utm_content' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.utm_term' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.acquisition_source' => ['nullable', 'string', 'max:160'],
            'acquisition_attribution.acquisition_landing' => ['nullable', 'string', 'max:1000'],
            'acquisition_attribution.acquisition_captured_at' => ['nullable', 'date'],
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

        [$order, $establishment] = DB::transaction(function () use ($data, $user) {
            $establishment = Establishment::query()
                ->whereKey($data['establishment_id'])
                ->forApplication($this->context->id())
                ->where('is_cancelled', false)
                ->where('is_published', true)
                ->lockForUpdate()
                ->firstOrFail();

            $config = $this->orderingConfig($establishment);
            abort_unless($config['available'], 422, $config['unavailable_reason'] ?: 'O estabelecimento não está recebendo pedidos agora.');
            abort_unless($config['fulfillment'][$data['fulfillment']] ?? false, 422, 'Modalidade de atendimento indisponível.');
            abort_unless(in_array($data['payment_method'], $config['payment_methods'], true), 422, 'Forma de pagamento indisponível.');

            if ($data['fulfillment'] === 'delivery') {
                abort_if(trim((string) ($data['delivery_address'] ?? '')) === '', 422, 'Informe o endereço de entrega.');
            }

            $primaryIds = collect($data['items'])->pluck('item_id')->map(fn ($id) => (int) $id)->unique();
            $modifierIds = collect($data['items'])
                ->flatMap(fn ($row) => array_merge($row['additions'] ?? [], $row['removals'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->unique();

            $catalog = Item::query()
                ->whereIn('id', $primaryIds->merge($modifierIds)->unique())
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->where('status', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_unless($primaryIds->every(fn ($id) => $catalog->has($id)), 422, 'Há item indisponível no carrinho.');
            abort_unless($modifierIds->every(fn ($id) => $catalog->has($id)), 422, 'Há adicional indisponível no carrinho.');

            $subtotal = 0.0;
            $stockDemand = [];
            $preparedLines = [];

            foreach ($data['items'] as $row) {
                $item = $catalog->get((int) $row['item_id']);
                $quantity = (int) $row['quantity'];
                abort_if($item->type === 'modifier', 422, "{$item->name} não pode ser item principal.");

                $lineTotal = (float) $item->price * $quantity;
                $stockDemand[$item->id] = ($stockDemand[$item->id] ?? 0) + $quantity;
                $additions = [];

                foreach ($row['additions'] ?? [] as $modifierId) {
                    $modifier = $catalog->get((int) $modifierId);
                    abort_unless($modifier, 422, 'Adicional inválido.');
                    $lineTotal += (float) $modifier->price * $quantity;
                    $stockDemand[$modifier->id] = ($stockDemand[$modifier->id] ?? 0) + $quantity;
                    $additions[] = $modifier->id;
                }

                $removals = collect($row['removals'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $catalog->has($id))
                    ->values()
                    ->all();

                $subtotal += $lineTotal;
                $preparedLines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'subtotal' => $lineTotal,
                    'additions' => $additions,
                    'removals' => $removals,
                    'notes' => $row['notes'] ?? null,
                ];
            }

            foreach ($stockDemand as $itemId => $quantity) {
                $item = $catalog->get((int) $itemId);
                abort_if((int) $item->stock < $quantity, 422, "Estoque insuficiente para {$item->name}.");
            }

            $minimumOrder = (float) ($establishment->minimum_order ?? 0);
            abort_if($subtotal < $minimumOrder, 422, 'Pedido mínimo: R$ ' . number_format($minimumOrder, 2, ',', '.'));

            $deliveryFee = $data['fulfillment'] === 'delivery' ? (float) ($establishment->delivery_fee ?? 0) : 0.0;

            $order = Order::query()->forceCreate([
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
                'total_price' => $subtotal + $deliveryFee,
                'status' => 'pending',
                'status_updated_at' => now(),
                'notes' => $data['notes'] ?? null,
                'type' => 'direct',
            ]);

            foreach ($preparedLines as $line) {
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => (float) $line['item']->price,
                    'subtotal' => $line['subtotal'],
                    'notes' => $line['notes'],
                ]);

                foreach ($line['additions'] as $modifierId) {
                    DB::table('order_item_modifiers')->insert([
                        'order_item_id' => $orderItem->id,
                        'modifier_id' => $modifierId,
                        'type' => 'addition',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($line['removals'] as $modifierId) {
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

            return [$order->fresh(['items.item']), $establishment];
        }, 3);

        $payment = $data['payment_method'] === 'pix'
            ? $this->createPixPayment($order, $establishment, $user, $data['acquisition_attribution'] ?? [])
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Pedido criado com sucesso.',
            'data' => [
                'order' => $this->serializeOrder($order->fresh(['items.item'])),
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
        $model = $this->manageable($request, $establishment);
        $query = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $model->id)
            ->with(['items.item'])
            ->latest('id');

        if ($request->filled('status')) $query->where('status', $request->string('status'));
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

            $this->manageable($request, (int) $model->entity_id);
            $previous = (string) $model->status;
            abort_if($previous === 'cancelled' && $data['status'] !== 'cancelled', 422, 'Pedido cancelado não pode ser reaberto automaticamente.');
            abort_if($previous === 'completed' && $data['status'] !== 'completed', 422, 'Pedido concluído não pode ser reaberto automaticamente.');

            if ($data['status'] === 'cancelled' && $previous !== 'cancelled') {
                abort_if($model->isPaid(), 422, 'Pedido pago exige estorno antes do cancelamento.');
                $this->restoreStock($model);
            }

            $model->forceFill([
                'status' => $data['status'],
                'status_updated_at' => now(),
                'attended_at' => $data['status'] === 'completed' ? now() : $model->attended_at,
            ])->save();

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
            ->forApplication($this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->get(['id', 'name', 'fantasy', 'slug', 'logo', 'accepting_orders']);

        $start = Carbon::now('America/Sao_Paulo')->startOfDay()->utc();
        $end = Carbon::now('America/Sao_Paulo')->endOfDay()->utc();
        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishments->pluck('id'))
            ->whereBetween('created_at', [$start, $end])
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'entity_id', 'total_price']);

        $grouped = $orders->groupBy('entity_id');
        $rows = $establishments->map(function ($establishment) use ($grouped) {
            $set = $grouped->get($establishment->id, collect());
            $revenue = (float) $set->sum('total_price');
            return [
                'establishment' => $establishment,
                'orders' => $set->count(),
                'revenue' => round($revenue, 2),
                'average_ticket' => $set->count() ? round($revenue / $set->count(), 2) : 0,
            ];
        })->values();

        $revenue = (float) $orders->sum('total_price');
        $count = $orders->count();
        return response()->json(['success' => true, 'data' => [
            'totals' => [
                'orders' => $count,
                'revenue' => round($revenue, 2),
                'average_ticket' => $count ? round($revenue / $count, 2) : 0,
                'establishments' => $establishments->count(),
            ],
            'establishments' => $rows,
        ]]);
    }

    public function paymentWebhook(Request $request): JsonResponse
    {
        $dataId = (string) ($request->input('data.id') ?: $request->query('data_id') ?: '');
        if ($dataId === '') return response()->json(['success' => true]);

        abort_unless(
            $this->paymentProvider->validateWebhookSignature(
                $request->header('x-signature'),
                $request->header('x-request-id'),
                $dataId,
            ),
            401,
            'Assinatura de webhook inválida.'
        );

        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Provedor de pagamento não configurado.');
        $remote = $this->paymentProvider->getPayment($token, $dataId);

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('app_slug', $this->context->slug())
            ->where('provider', 'mercadopago')
            ->where('provider_payment_id', (string) ($remote['id'] ?? $dataId))
            ->first();

        if (! $payment) return response()->json(['success' => true]);

        $remoteId = (string) ($remote['id'] ?? '');
        $externalReference = (string) ($remote['external_reference'] ?? '');
        $remoteAmount = round((float) ($remote['transaction_amount'] ?? 0), 2);
        abort_if($remoteId === '' || $remoteId !== (string) $payment->provider_payment_id, 422, 'Pagamento remoto não corresponde ao pagamento local.');
        abort_if($externalReference === '' || $externalReference !== (string) $payment->source_reference, 422, 'Referência externa do pagamento é inválida.');
        abort_if(abs($remoteAmount - (float) $payment->gross_amount) > 0.009, 422, 'Valor confirmado pelo provedor é diferente do pedido.');

        $mapped = match ((string) ($remote['status'] ?? '')) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };

        DB::transaction(function () use ($payment, $mapped, $remote) {
            $providerFee = (float) collect($remote['fee_details'] ?? [])->sum('amount');
            $payment->forceFill([
                'status' => $mapped,
                'provider_fee' => $providerFee,
                'seller_net' => max(0, (float) $payment->gross_amount - $providerFee - (float) $payment->platform_fee),
                'paid_at' => $mapped === 'paid' ? now() : $payment->paid_at,
                'refunded_at' => $mapped === 'refunded' ? now() : $payment->refunded_at,
                'failed_at' => $mapped === 'failed' ? now() : $payment->failed_at,
                'metadata' => array_merge($payment->metadata ?? [], ['last_webhook_status' => $remote['status'] ?? null]),
            ])->save();

            if ($payment->source_type === 'order' && $payment->source_id) {
                Order::query()->whereKey($payment->source_id)->where('app_id', $this->context->id())->update([
                    'payment_status' => $mapped,
                    'payment_reference' => $payment->provider_payment_id,
                    'updated_at' => now(),
                ]);
            }
        });

        if ($mapped === 'paid') {
            $this->recordPaidInteraction((int) $payment->id);
        }

        return response()->json(['success' => true]);
    }

    private function recordPaidInteraction(int $paymentId): void
    {
        try {
            $payment = EcosystemPayment::query()
                ->where('app_id', $this->context->id())
                ->whereKey($paymentId)
                ->first();
            if (! $payment || $payment->status !== 'paid' || $payment->source_type !== 'order' || ! $payment->source_id) return;

            $order = Order::query()
                ->where('app_id', $this->context->id())
                ->whereKey($payment->source_id)
                ->first();
            if (! $order || $order->payment_status !== 'paid') return;

            $alreadyRecorded = Interaction::query()
                ->where('app_id', $this->context->id())
                ->where('entity_type', 'Order')
                ->where('entity_id', $order->id)
                ->where('interaction_type', 'payment_paid')
                ->exists();
            if ($alreadyRecorded) return;

            $attribution = data_get($payment->metadata, 'acquisition_attribution', []);
            if (! is_array($attribution)) $attribution = [];

            Interaction::register('payment_paid', $order, $order->client, array_merge([
                'source_channel' => 'payment_provider',
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'establishment_id' => $payment->establishment_id ?: $order->entity_id,
                'payment_method' => $payment->method ?: $order->payment_method,
                'currency' => $payment->currency,
                'amount' => (float) $payment->gross_amount,
                'platform_fee' => (float) $payment->platform_fee,
                'provider_fee' => (float) $payment->provider_fee,
                'seller_net' => (float) $payment->seller_net,
            ], $attribution), 'Pagamento confirmado');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function createPixPayment(Order $order, Establishment $establishment, $user, array $acquisitionAttribution = []): array
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

            $order->forceFill(['payment_status' => 'failed'])->save();
            return [
                'provider' => 'unavailable',
                'status' => 'failed',
                'amount' => (float) $order->total_price,
                'message' => 'Pix temporariamente indisponível. O pedido foi preservado; escolha outra forma de pagamento.',
            ];
        }

        $publicId = (string) Str::uuid();
        $reference = $this->context->slug() . '-order-' . $order->id . '-' . $publicId;
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
            'metadata' => [
                'order_number' => $order->order_number,
                'acquisition_attribution' => $acquisitionAttribution,
            ],
        ]);

        try {
            $remote = $this->paymentProvider->createPayment($token, [
                'transaction_amount' => (float) $order->total_price,
                'description' => 'Pedido #' . $order->order_number,
                'payment_method_id' => 'pix',
                'external_reference' => $reference,
                'notification_url' => rtrim((string) config('app.url'), '/') . '/api/v1/apps/' . $this->context->slug() . '/payments/mercadopago/webhook',
                'payer' => [
                    'email' => $user->email,
                    'first_name' => $user->first_name ?: $order->customer_name,
                    'last_name' => $user->last_name ?: '',
                ],
            ], $this->context->slug() . '-order-' . $order->id);

            $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
            $payment->forceFill([
                'provider_payment_id' => (string) ($remote['id'] ?? ''),
                'metadata' => array_merge($payment->metadata ?? [], ['remote_status' => $remote['status'] ?? null]),
            ])->save();
            $order->forceFill(['payment_reference' => $payment->provider_payment_id])->save();

            return [
                'provider' => 'mercadopago',
                'public_id' => $payment->public_id,
                'status' => 'pending',
                'amount' => (float) $order->total_price,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $payment->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], ['error' => $exception->getMessage()]),
            ])->save();
            $order->forceFill(['payment_status' => 'failed'])->save();

            if (! empty($establishment->pix_key)) {
                return [
                    'provider' => 'manual_pix',
                    'status' => 'pending',
                    'pix_key' => $establishment->pix_key,
                    'amount' => (float) $order->total_price,
                    'fallback' => true,
                ];
            }

            return [
                'provider' => 'mercadopago',
                'status' => 'failed',
                'amount' => (float) $order->total_price,
                'retryable' => true,
                'message' => 'O provedor de Pix não respondeu, mas seu pedido foi criado e preservado.',
            ];
        }
    }

    private function publicEstablishment(string $slug): Establishment
    {
        return Establishment::query()
            ->forApplication($this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->firstOrFail();
    }

    private function manageable(Request $request, int $id): Establishment
    {
        $establishment = Establishment::query()
            ->whereKey($id)
            ->forApplication($this->context->id())
            ->where('is_cancelled', false)
            ->firstOrFail();

        $userId = (int) $request->user()->id;
        $isOwner = (int) $establishment->user_id === $userId;
        $isEmployee = Employer::query()->where('establishment_id', $establishment->id)->where('user_id', $userId)->exists();
        abort_unless($isOwner || $isEmployee, 403, 'Você não possui acesso a esta operação.');
        return $establishment;
    }

    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $line) {
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

    private function orderingConfig(Establishment $establishment): array
    {
        $orderingEnabled = (bool) ($establishment->ordering_enabled ?? true);
        $acceptingOrders = (bool) ($establishment->accepting_orders ?? true);
        $openNow = $this->isOpenNow($establishment);
        $pixConfigured = trim((string) config('services.mercadopago.access_token')) !== '' || ! empty($establishment->pix_key);
        $fallbackMethods = $pixConfigured ? ['pix', 'cash', 'card_on_delivery'] : ['cash', 'card_on_delivery'];
        $paymentMethods = array_values(array_filter(
            $this->json($establishment->payment_methods, $fallbackMethods),
            fn ($method) => $method !== 'pix' || $pixConfigured,
        ));

        return [
            'available' => $orderingEnabled && $acceptingOrders && $openNow,
            'open_now' => $openNow,
            'accepting_orders' => $acceptingOrders,
            'ordering_enabled' => $orderingEnabled,
            'unavailable_reason' => ! $orderingEnabled
                ? 'Pedidos online estão desativados.'
                : (! $acceptingOrders ? 'O estabelecimento pausou novos pedidos.' : (! $openNow ? 'O estabelecimento está fechado agora.' : null)),
            'fulfillment' => [
                'delivery' => (bool) ($establishment->delivery_enabled ?? true),
                'pickup' => (bool) ($establishment->pickup_enabled ?? true),
                'dine-in' => (bool) ($establishment->dine_in_enabled ?? false),
            ],
            'delivery_fee' => (float) ($establishment->delivery_fee ?? 0),
            'minimum_order' => (float) ($establishment->minimum_order ?? 0),
            'estimated_delivery_minutes' => $establishment->estimated_delivery_minutes ? (int) $establishment->estimated_delivery_minutes : null,
            'opening_hours' => $this->json($establishment->opening_hours, []),
            'payment_methods' => $paymentMethods,
            'pix_configured' => $pixConfigured,
        ];
    }

    private function isOpenNow(Establishment $establishment): bool
    {
        $hours = $this->json($establishment->opening_hours, []);
        if ($hours === []) return true;

        $now = Carbon::now('America/Sao_Paulo');
        foreach ([0, -1] as $offset) {
            $day = $now->copy()->addDays($offset);
            $ranges = null;
            foreach ([strtolower($day->format('l')), (string) $day->dayOfWeekIso] as $key) {
                if (array_key_exists($key, $hours)) {
                    $ranges = $hours[$key];
                    break;
                }
            }

            if ($ranges === true || $ranges === '24h') return true;
            if (! is_array($ranges)) continue;
            if (isset($ranges['open'], $ranges['close'])) $ranges = [$ranges];

            foreach ($ranges as $range) {
                if (! is_array($range) || empty($range['open']) || empty($range['close'])) continue;
                try {
                    $open = Carbon::parse($day->toDateString() . ' ' . $range['open'], 'America/Sao_Paulo');
                    $close = Carbon::parse($day->toDateString() . ' ' . $range['close'], 'America/Sao_Paulo');
                    if ($close->lte($open)) $close->addDay();
                    if ($now->betweenIncluded($open, $close)) return true;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return false;
    }

    private function json($value, array $fallback): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function serializeOrder(Order $order): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->forApplication($this->context->id())
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
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn ($line) => [
                    'id' => $line->id,
                    'item_id' => $line->item_id,
                    'name' => $line->item?->name,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'subtotal' => (float) $line->subtotal,
                    'notes' => $line->notes ?? null,
                ])->values()
                : [],
        ];
    }
}
