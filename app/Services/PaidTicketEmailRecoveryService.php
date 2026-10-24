<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CommerceOrder;
use Illuminate\Support\Facades\DB;

final class PaidTicketEmailRecoveryService
{
    /**
     * Retry unsent buyer emails across payment providers. Never issues a pass,
     * changes a payment or repeats a completed delivery.
     *
     * @return array{eligible:int,attempted:int,sent:int,failed:int,skipped:int}
     */
    public function run(int $limit = 25, int $retryAfterMinutes = 15, ?string $applicationSlug = null): array
    {
        $limit = max(1, min($limit, 100));
        $retryAfterMinutes = max(10, min($retryAfterMinutes, 1440));

        $result = ['eligible' => 0, 'attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $appId = null;

        if ($applicationSlug !== null && trim($applicationSlug) !== '') {
            $appId = Application::query()->where('slug', trim($applicationSlug))->value('id');
            if (! $appId) {
                return $result;
            }
        }

        $recentFailureCutoff = now()->subMinutes($retryAfterMinutes);
        $recentClaimCutoff = now()->subMinutes(10);

        $orderIds = CommerceOrder::query()
            ->select('commerce_orders.id')
            ->where('commerce_orders.status', 'paid')
            ->where('commerce_orders.paid_at', '<=', now()->subMinute())
            ->when($appId, fn ($query) => $query->where('commerce_orders.app_id', $appId))
            ->whereHas('event')
            ->whereHas('user', fn ($query) => $query->whereNotNull('email')->where('email', '!=', ''))
            ->whereHas('items', fn ($query) => $query->where('type', 'ticket')->where('quantity', '>', 0))
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('event_passes as ep')
                    ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
                    ->whereColumn('coi.order_id', 'commerce_orders.id')
                    ->whereColumn('coi.app_id', 'commerce_orders.app_id')
                    ->whereColumn('ep.event_id', 'commerce_orders.event_id')
                    ->whereColumn('ep.user_id', 'commerce_orders.user_id')
                    ->where('coi.type', 'ticket')
                    ->whereNotIn('ep.status', ['cancelled', 'refunded', 'charged_back']);
            })
            ->whereNotExists(function ($query) use ($recentFailureCutoff, $recentClaimCutoff) {
                $query->selectRaw('1')
                    ->from('delivery_effects as de')
                    ->whereColumn('de.app_id', 'commerce_orders.app_id')
                    ->whereColumn('de.aggregate_id', 'commerce_orders.id')
                    ->where('de.aggregate_type', 'commerce_order')
                    ->where('de.effect_key', 'buyer_event_passes_email')
                    ->where(function ($statuses) use ($recentFailureCutoff, $recentClaimCutoff) {
                        $statuses->where('de.status', 'completed')
                            ->orWhere(function ($recent) use ($recentFailureCutoff) {
                                $recent->where('de.status', 'failed')->where('de.updated_at', '>', $recentFailureCutoff);
                            })
                            ->orWhere(function ($recent) use ($recentClaimCutoff) {
                                $recent->where('de.status', 'processing')->where('de.claimed_at', '>', $recentClaimCutoff);
                            });
                    });
            })
            ->orderBy('commerce_orders.id')
            ->limit($limit * 10)
            ->pluck('commerce_orders.id');

        $result['eligible'] = $orderIds->count();

        foreach ($orderIds as $orderId) {
            if ($result['attempted'] >= $limit) {
                break;
            }

            $sent = app(EventAudienceService::class)->deliverPaidTicketEmail((int) $orderId);
            if ($sent === null) {
                $result['skipped']++;
                continue;
            }

            $result['attempted']++;
            if ($sent) {
                $result['sent']++;
            } else {
                $status = DB::table('delivery_effects')
                    ->where('aggregate_type', 'commerce_order')
                    ->where('aggregate_id', (string) $orderId)
                    ->where('effect_key', 'buyer_event_passes_email')
                    ->value('status');

                if ($status === 'failed') {
                    $result['failed']++;
                } else {
                    $result['skipped']++;
                }
            }
        }

        return $result;
    }
}
