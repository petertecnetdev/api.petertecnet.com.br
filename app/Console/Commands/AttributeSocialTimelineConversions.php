<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AttributeSocialTimelineConversions extends Command
{
    protected $signature = 'social:attribute-conversions {--limit=200}';
    protected $description = 'Attributes paid event orders to the latest eligible timeline ticket click.';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $orders = DB::table('commerce_orders as o')
            ->whereIn('o.status', ['paid', 'refunded', 'charged_back'])
            ->whereNotNull('o.user_id')
            ->whereNotNull('o.event_id')
            ->where('o.updated_at', '>', now()->subDays(7))
            ->orderBy('o.id')
            ->limit($limit)
            ->get(['o.id', 'o.app_id', 'o.user_id', 'o.event_id', 'o.subtotal', 'o.platform_fee', 'o.status', 'o.created_at', 'o.paid_at']);

        $attributed = 0;
        foreach ($orders as $order) {
            $conversion = DB::table('social_post_events')
                ->where('app_id', $order->app_id)
                ->where('order_id', $order->id)
                ->where('event_type', 'conversion')
                ->first();

            if (! $conversion && $order->status === 'paid') {
                $orderCreatedAt = Carbon::parse($order->created_at);
                $touch = DB::table('social_post_events as se')
                    ->join('social_posts as p', 'p.id', '=', 'se.post_id')
                    ->where('se.app_id', $order->app_id)
                    ->where('se.user_id', $order->user_id)
                    ->where('se.event_type', 'ticket_click')
                    ->where('p.app_id', $order->app_id)
                    ->where('p.event_id', $order->event_id)
                    ->where('p.status', 'published')
                    ->whereBetween('se.created_at', [$orderCreatedAt->copy()->subHours(48), $orderCreatedAt->copy()->addMinutes(10)])
                    ->orderByDesc('se.created_at')
                    ->first(['se.post_id', 'se.created_at', 'p.source', 'p.campaign', 'p.promoter_id']);

                if ($touch) {
                    $inserted = DB::table('social_post_events')->insertOrIgnore([
                        'app_id' => $order->app_id,
                        'post_id' => $touch->post_id,
                        'user_id' => $order->user_id,
                        'order_id' => $order->id,
                        'event_type' => 'conversion',
                        'session_key' => null,
                        'value_cents' => max(0, (int) round(((float) $order->subtotal) * 100)),
                        'metadata' => json_encode([
                            'event_id' => $order->event_id,
                            'attribution_model' => 'last_ticket_click_48h',
                            'source' => $touch->source ?: 'timeline',
                            'campaign' => $touch->campaign,
                            'promoter_id' => $touch->promoter_id,
                            'touch_at' => $touch->created_at,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                    ]);
                    if ($inserted) {
                        DB::table('social_post_metrics')->updateOrInsert(
                            ['app_id' => $order->app_id, 'post_id' => $touch->post_id],
                            ['created_at' => now(), 'updated_at' => now()]
                        );
                        DB::table('social_post_metrics')->where(['app_id' => $order->app_id, 'post_id' => $touch->post_id])->increment('conversions');
                        DB::table('social_post_metrics')->where(['app_id' => $order->app_id, 'post_id' => $touch->post_id])->increment('gmv_cents', max(0, (int) round(((float) $order->subtotal) * 100)));
                        DB::table('social_post_metrics')->where(['app_id' => $order->app_id, 'post_id' => $touch->post_id])->increment('platform_revenue_cents', max(0, (int) round(((float) $order->platform_fee) * 100)));
                        $attributed++;
                    }
                }
            }

            if ($conversion && in_array($order->status, ['refunded', 'charged_back'], true)) {
                $reversed = DB::table('social_post_events')
                    ->where('app_id', $order->app_id)->where('post_id', $conversion->post_id)
                    ->where('order_id', $order->id)->where('event_type', 'conversion_reversed')->exists();
                if (! $reversed) {
                    DB::table('social_post_events')->insertOrIgnore([
                        'app_id' => $order->app_id, 'post_id' => $conversion->post_id, 'user_id' => $order->user_id, 'order_id' => $order->id,
                        'event_type' => 'conversion_reversed', 'session_key' => null,
                        'value_cents' => max(0, (int) round(((float) $order->subtotal) * 100)),
                        'metadata' => json_encode(['reason' => $order->status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => now(),
                    ]);
                    $metric = DB::table('social_post_metrics')->where(['app_id' => $order->app_id, 'post_id' => $conversion->post_id])->first();
                    if ($metric) DB::table('social_post_metrics')->where('id', $metric->id)->update([
                        'conversions' => max(0, (int) $metric->conversions - 1),
                        'gmv_cents' => max(0, (int) $metric->gmv_cents - max(0, (int) round(((float) $order->subtotal) * 100))),
                        'platform_revenue_cents' => max(0, (int) $metric->platform_revenue_cents - max(0, (int) round(((float) $order->platform_fee) * 100))),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->info('Timeline conversions attributed: '.$attributed);
        return self::SUCCESS;
    }
}
