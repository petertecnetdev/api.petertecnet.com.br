<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceCoupon;
use App\Models\CommerceCouponRedemption;
use App\Models\CommerceOrder;
use Illuminate\Validation\ValidationException;

final class CommerceCouponService
{
    public function validateForCheckout(int $appId, int $eventId, int $productionId, int $userId, string $code, float $subtotal): array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw ValidationException::withMessages(['coupon_code' => 'Informe um cupom.']);
        }

        $coupon = CommerceCoupon::query()
            ->where('app_id', $appId)
            ->where('code', $normalized)
            ->where('production_id', $productionId)
            ->lockForUpdate()
            ->first();

        if (! $coupon || ! $coupon->is_active) {
            throw ValidationException::withMessages(['coupon_code' => 'Cupom inválido ou inativo.']);
        }
        if ($coupon->event_id && (int) $coupon->event_id !== $eventId) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom não é válido para este evento.']);
        }
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom ainda não está disponível.']);
        }
        if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom expirou.']);
        }
        if ($coupon->max_uses !== null && (int) $coupon->uses_count >= (int) $coupon->max_uses) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom atingiu o limite de utilizações.']);
        }
        if ($subtotal < (float) $coupon->minimum_subtotal) {
            throw ValidationException::withMessages(['coupon_code' => 'O valor mínimo para usar este cupom é R$ '.number_format((float)$coupon->minimum_subtotal, 2, ',', '.').'.']);
        }

        $userUses = CommerceCouponRedemption::query()->where('coupon_id', $coupon->id)->where('user_id', $userId)->count();
        if ($userUses >= max(1, (int) $coupon->max_uses_per_user)) {
            throw ValidationException::withMessages(['coupon_code' => 'Você já utilizou este cupom o máximo de vezes permitido.']);
        }

        $discount = $coupon->discount_type === 'percentage'
            ? round($subtotal * min(100, max(0, (float)$coupon->discount_value)) / 100, 2)
            : min($subtotal, round(max(0, (float)$coupon->discount_value), 2));
        $discount = min($subtotal, max(0, $discount));

        return [
            'coupon' => $coupon,
            'code' => $coupon->code,
            'discount_amount' => $discount,
            'subtotal' => round($subtotal, 2),
            'total' => round(max(0, $subtotal - $discount), 2),
        ];
    }

    public function applyToOrder(CommerceOrder $order, ?string $requestedCode = null): void
    {
        $code = trim((string)($requestedCode ?: data_get($order->metadata, 'coupon_code', '')));
        if ($code === '' || (float)$order->subtotal <= 0) return;

        $result = $this->validateForCheckout(
            (int)$order->app_id,
            (int)$order->event_id,
            (int)$order->production_id,
            (int)$order->user_id,
            $code,
            (float)$order->subtotal,
        );

        $originalSubtotal = max(0.01, (float)$order->subtotal);
        $feeRate = max(0, (float)$order->platform_fee / $originalSubtotal);
        $discount = (float)$result['discount_amount'];
        $total = (float)$result['total'];
        $platformFee = round($total * $feeRate, 2);

        $order->discount_amount = $discount;
        $order->total = $total;
        $order->platform_fee = $platformFee;
        $order->producer_net = max(0, $total - $platformFee);
        $order->metadata = array_merge($order->metadata ?? [], [
            'coupon_id' => (int)$result['coupon']->id,
            'coupon_code' => $result['code'],
            'coupon_discount_amount' => $discount,
        ]);
    }

    public function redeemPaidOrder(CommerceOrder $order): void
    {
        $couponId = (int)data_get($order->metadata, 'coupon_id', 0);
        if ($couponId <= 0 || (float)$order->discount_amount <= 0) return;

        $coupon = CommerceCoupon::query()->whereKey($couponId)->lockForUpdate()->first();
        if (! $coupon) return;

        $created = CommerceCouponRedemption::query()->firstOrCreate(
            ['coupon_id' => $coupon->id, 'order_id' => $order->id],
            [
                'app_id' => $order->app_id,
                'user_id' => $order->user_id,
                'discount_amount' => $order->discount_amount,
                'redeemed_at' => now(),
            ]
        );

        if ($created->wasRecentlyCreated) {
            $coupon->increment('uses_count');
        }
    }
}
