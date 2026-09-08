<?php

namespace App\Services;

use App\Models\CommerceOrder;
use App\Models\PromotionCampaign;
use App\Models\PromotionCampaignAttribution;

class PromotionCampaignAttributionService
{
    public function recordPaidOrder(CommerceOrder $order): void
    {
        if ($order->status !== 'paid') return;

        $campaignUuid = trim((string) data_get($order->metadata, 'campaign_uuid', ''));
        if ($campaignUuid === '') return;

        $campaign = PromotionCampaign::query()
            ->where('uuid', $campaignUuid)
            ->where('app_id', $order->app_id)
            ->where('event_id', $order->event_id)
            ->first();

        if (! $campaign) return;

        $settlementMode = (string) data_get($order->metadata, 'settlement_mode', 'unknown');
        $platformFee = (float) $order->platform_fee;
        $processorFee = (float) $order->processor_fee;
        $platformContribution = $settlementMode === 'platform_collection'
            ? $platformFee - $processorFee
            : $platformFee;

        PromotionCampaignAttribution::query()->updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'order_id' => $order->id,
                'touchpoint' => 'purchase',
            ],
            [
                'user_id' => $order->user_id,
                'gmv' => round((float) $order->total, 2),
                'platform_revenue' => round($platformContribution, 2),
                'producer_cost' => round((float) $order->discount_amount, 2),
                'currency' => $order->currency ?: 'BRL',
                'idempotency_key' => 'paid-order:'.$order->id,
                'metadata' => [
                    'subtotal' => round((float) $order->subtotal, 2),
                    'platform_fee' => round($platformFee, 2),
                    'processor_fee' => round($processorFee, 2),
                    'discount_amount' => round((float) $order->discount_amount, 2),
                    'settlement_mode' => $settlementMode,
                    'payment_method' => $order->payment_method,
                    'order_public_id' => $order->public_id,
                ],
            ]
        );
    }
}
