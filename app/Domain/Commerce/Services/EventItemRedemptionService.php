<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EventItemRedemptionService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function credentialFor(User $actor, string $publicId): array
    {
        $order = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->with(['items', 'event', 'production'])
            ->firstOrFail();

        abort_unless(
            (int) $order->user_id === (int) $actor->id || $this->canOperateProduction($actor, $order),
            403
        );

        return $this->credential($order);
    }

    public function credential(CommerceOrder $order): array
    {
        $this->assertRedeemableOrder($order);
        $redemption = DB::table('commerce_order_redemptions')
            ->where('app_id', $this->context->id())
            ->where('order_id', $order->id)
            ->first();

        return [
            'token' => $this->tokenFor($order),
            'status' => $redemption?->status ?: 'active',
            'redeemed_at' => $redemption?->redeemed_at,
            'event' => $order->event?->only(['id', 'title', 'slug', 'start_date', 'end_date']),
            'items' => $this->itemPayload($order),
            'available_from' => $order->event?->start_date?->copy()->startOfDay(),
            'available_until' => ($order->event?->end_date ?: $order->event?->start_date)?->copy()->endOfDay(),
        ];
    }

    public function redeem(User $actor, string $token, int $eventId): array
    {
        [$publicId, $signature] = $this->parseToken($token);
        $expected = $this->signatureFor($publicId);
        abort_unless(hash_equals($expected, $signature), 422, 'QR Code de retirada inválido.');

        return DB::transaction(function () use ($actor, $publicId, $eventId) {
            $order = CommerceOrder::query()
                ->where('app_id', $this->context->id())
                ->where('public_id', $publicId)
                ->where('event_id', $eventId)
                ->with(['items', 'event', 'production'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertProducerCanRedeem($actor, $order);
            $this->assertRedeemableOrder($order);
            $this->assertRedemptionWindow($order);

            $existing = DB::table('commerce_order_redemptions')
                ->where('app_id', $this->context->id())
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            abort_if($existing && $existing->status === 'redeemed', 409, 'Este QR Code de retirada já foi utilizado.');

            $now = now();
            if ($existing) {
                DB::table('commerce_order_redemptions')->where('id', $existing->id)->update([
                    'status' => 'redeemed',
                    'redeemed_by_user_id' => $actor->id,
                    'redeemed_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('commerce_order_redemptions')->insert([
                    'app_id' => $this->context->id(),
                    'order_id' => $order->id,
                    'event_id' => $order->event_id,
                    'status' => 'redeemed',
                    'redeemed_by_user_id' => $actor->id,
                    'redeemed_at' => $now,
                    'metadata' => json_encode(['order_public_id' => $order->public_id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'message' => 'Retirada confirmada. Entregue os itens deste pedido.',
                'status' => 'redeemed',
                'redeemed_at' => $now,
                'order_public_id' => $order->public_id,
                'items' => $this->itemPayload($order),
            ];
        });
    }

    private function assertRedeemableOrder(CommerceOrder $order): void
    {
        abort_unless($order->status === 'paid', 422, 'A retirada só é liberada após a confirmação do pagamento.');
        abort_unless($order->items->where('type', 'item')->isNotEmpty(), 422, 'Este pedido não possui itens para retirada.');
    }

    private function assertProducerCanRedeem(User $actor, CommerceOrder $order): void
    {
        abort_unless($this->canOperateProduction($actor, $order), 403);
    }

    private function canOperateProduction(User $actor, CommerceOrder $order): bool
    {
        $production = $order->production ?: Production::query()
            ->where('app_id', $this->context->id())
            ->find($order->production_id);
        $admin = method_exists($actor, 'hasProfile') && $actor->hasProfile('Administrador');

        return (bool) ($production && ($admin || (int) $production->user_id === (int) $actor->id));
    }

    private function assertRedemptionWindow(CommerceOrder $order): void
    {
        abort_unless($order->event, 422, 'Evento da retirada indisponível.');
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::parse($order->event->start_date, $timezone)->startOfDay();
        $end = Carbon::parse($order->event->end_date ?: $order->event->start_date, $timezone)->endOfDay();
        $now = Carbon::now($timezone);
        abort_if($now->lt($start), 422, 'A retirada será liberada no dia do evento.');
        abort_if($now->gt($end), 422, 'O período de retirada deste pedido já terminou.');
    }

    private function tokenFor(CommerceOrder $order): string
    {
        return 'ITEM-'.$order->public_id.'.'.$this->signatureFor((string) $order->public_id);
    }

    private function signatureFor(string $publicId): string
    {
        $key = (string) config('app.key');
        if ($key === '') throw new RuntimeException('APP_KEY é obrigatória para emitir credenciais de retirada.');
        return hash_hmac('sha256', 'event-item-pickup:'.$this->context->id().':'.$publicId, $key);
    }

    private function parseToken(string $token): array
    {
        $token = trim($token);
        abort_unless(preg_match('/^ITEM-([0-9a-fA-F-]{36})\.([0-9a-f]{64})$/', $token, $matches) === 1, 422, 'QR Code de retirada inválido.');
        return [strtolower($matches[1]), $matches[2]];
    }

    private function itemPayload(CommerceOrder $order): array
    {
        return $order->items->where('type', 'item')->map(fn ($line) => [
            'id' => $line->id,
            'event_item_id' => $line->event_item_id,
            'name' => $line->name,
            'quantity' => (int) $line->quantity,
        ])->values()->all();
    }
}
