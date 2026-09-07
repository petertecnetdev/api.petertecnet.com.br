<?php

namespace App\Domain\Social\Services;

use App\Models\CommerceOrder;
use Illuminate\Support\Facades\DB;

final class SocialAttributionService
{
    public function sanitizeForEvent(array $attribution, int $appId, int $eventId): array
    {
        $postId = (int) ($attribution['post_id'] ?? 0);
        if ($postId <= 0) {
            return [];
        }

        $post = DB::table('social_posts')
            ->where('app_id', $appId)
            ->where('id', $postId)
            ->where('status', 'published')
            ->first();

        if (! $post || ((int) ($post->event_id ?? 0) > 0 && (int) $post->event_id !== $eventId)) {
            return [];
        }

        $promoterId = isset($attribution['promoter_id']) ? (int) $attribution['promoter_id'] : null;

        return [
            'post_id' => $postId,
            'source' => mb_substr(trim((string) ($attribution['source'] ?? 'timeline')), 0, 64),
            'campaign' => ($campaign = trim((string) ($attribution['campaign'] ?? ''))) !== '' ? mb_substr($campaign, 0, 120) : null,
            'promoter_id' => $promoterId && $promoterId > 0 ? $promoterId : null,
        ];
    }

    public function recordPaidOrder(CommerceOrder $order): void
    {
        $attribution = data_get($order->metadata, 'social_attribution');
        if (! is_array($attribution) || empty($attribution['post_id'])) {
            return;
        }

        $postId = (int) $attribution['post_id'];
        $appId = (int) $order->app_id;
        $postExists = DB::table('social_posts')
            ->where('app_id', $appId)
            ->where('id', $postId)
            ->where('status', 'published')
            ->exists();

        if (! $postExists) {
            return;
        }

        $inserted = DB::table('social_post_events')->insertOrIgnore([
            'app_id' => $appId,
            'post_id' => $postId,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'event_type' => 'conversion',
            'session_key' => null,
            'value_cents' => $this->cents((float) $order->subtotal),
            'metadata' => json_encode([
                'event_id' => $order->event_id,
                'source' => $attribution['source'] ?? 'timeline',
                'campaign' => $attribution['campaign'] ?? null,
                'promoter_id' => $attribution['promoter_id'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        if (! $inserted) {
            return;
        }

        DB::table('social_post_metrics')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId],
            ['updated_at' => now(), 'created_at' => now()]
        );

        DB::table('social_post_metrics')
            ->where('app_id', $appId)
            ->where('post_id', $postId)
            ->incrementEach([
                'conversions' => 1,
                'gmv_cents' => $this->cents((float) $order->subtotal),
                'platform_revenue_cents' => $this->cents((float) $order->platform_fee),
            ], ['updated_at' => now()]);
    }

    public function recordReversal(CommerceOrder $order, string $reason): void
    {
        $attribution = data_get($order->metadata, 'social_attribution');
        if (! is_array($attribution) || empty($attribution['post_id'])) {
            return;
        }

        $postId = (int) $attribution['post_id'];
        $appId = (int) $order->app_id;
        $hadConversion = DB::table('social_post_events')
            ->where('app_id', $appId)
            ->where('post_id', $postId)
            ->where('order_id', $order->id)
            ->where('event_type', 'conversion')
            ->exists();

        if (! $hadConversion) {
            return;
        }

        $inserted = DB::table('social_post_events')->insertOrIgnore([
            'app_id' => $appId,
            'post_id' => $postId,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'event_type' => 'conversion_reversed',
            'session_key' => null,
            'value_cents' => $this->cents((float) $order->subtotal),
            'metadata' => json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        if (! $inserted) {
            return;
        }

        $metrics = DB::table('social_post_metrics')
            ->where('app_id', $appId)
            ->where('post_id', $postId)
            ->first();

        if (! $metrics) {
            return;
        }

        DB::table('social_post_metrics')
            ->where('id', $metrics->id)
            ->update([
                'conversions' => max(0, (int) $metrics->conversions - 1),
                'gmv_cents' => max(0, (int) $metrics->gmv_cents - $this->cents((float) $order->subtotal)),
                'platform_revenue_cents' => max(0, (int) $metrics->platform_revenue_cents - $this->cents((float) $order->platform_fee)),
                'updated_at' => now(),
            ]);
    }

    private function cents(float $value): int
    {
        return max(0, (int) round($value * 100));
    }
}
