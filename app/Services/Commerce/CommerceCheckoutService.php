<?php

namespace App\Services\Commerce;

use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommerceCheckoutService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceConfigurationService $configuration,
    ) {}

    public function create(User $user, array $data): array
    {
        return DB::transaction(function () use ($data, $user) {
            $establishment = Establishment::query()
                ->forApplication($this->context->id())
                ->whereKey($data['establishment_id'])
                ->where('is_cancelled', false)
                ->lockForUpdate()
                ->firstOrFail();

            $config = $this->configuration->forEstablishment($establishment);
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
                abort_if((float) $item->price <= 0, 422, "O item {$item->name} não está disponível para compra online.");

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
    }
}
