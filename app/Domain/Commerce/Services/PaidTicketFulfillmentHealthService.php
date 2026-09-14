<?php

namespace App\Domain\Commerce\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PaidTicketFulfillmentHealthService
{
    public function forApplication(int $appId, int $limit = 20, int $slaMinutes = 10): array
    {
        $limit = max(1, min($limit, 100));
        $slaMinutes = max(1, min($slaMinutes, 1440));
        $slaCutoff = now()->subMinutes($slaMinutes);

        $expected = DB::table('commerce_order_items')
            ->where('app_id', $appId)
            ->where('type', 'ticket')
            ->groupBy('order_id')
            ->select('order_id')
            ->selectRaw('SUM(quantity) as expected_passes');

        $emitted = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->where('coi.app_id', $appId)
            ->where('coi.type', 'ticket')
            ->groupBy('coi.order_id')
            ->select('coi.order_id')
            ->selectRaw('COUNT(ep.id) as emitted_passes');

        $base = DB::table('commerce_orders as co')
            ->joinSub($expected, 'expected', fn ($join) => $join->on('expected.order_id', '=', 'co.id'))
            ->leftJoinSub($emitted, 'emitted', fn ($join) => $join->on('emitted.order_id', '=', 'co.id'))
            ->leftJoin('events as e', 'e.id', '=', 'co.event_id')
            ->where('co.app_id', $appId)
            ->where('co.status', 'paid')
            ->whereRaw('COALESCE(emitted.emitted_passes, 0) < expected.expected_passes');

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as unresolved_orders')
            ->selectRaw('COALESCE(SUM(co.total), 0) as unresolved_gmv')
            ->selectRaw('COALESCE(SUM(expected.expected_passes - COALESCE(emitted.emitted_passes, 0)), 0) as missing_passes')
            ->selectRaw('MIN(COALESCE(co.paid_at, co.updated_at)) as oldest_unresolved_at')
            ->first();

        $overSlaOrders = (clone $base)
            ->where(fn ($query) => $query
                ->where('co.paid_at', '<=', $slaCutoff)
                ->orWhere(fn ($fallback) => $fallback
                    ->whereNull('co.paid_at')
                    ->where('co.updated_at', '<=', $slaCutoff)))
            ->count();

        $orders = (clone $base)
            ->orderByRaw('COALESCE(co.paid_at, co.updated_at) ASC')
            ->limit($limit)
            ->get([
                'co.id',
                'co.public_id',
                'co.event_id',
                'co.production_id',
                'co.total',
                'co.paid_at',
                'co.updated_at',
                'e.title as event_name',
                'expected.expected_passes',
                DB::raw('COALESCE(emitted.emitted_passes, 0) as emitted_passes'),
                DB::raw('(expected.expected_passes - COALESCE(emitted.emitted_passes, 0)) as missing_passes'),
            ])
            ->map(function ($row) use ($slaMinutes) {
                $referenceAt = $row->paid_at ?: $row->updated_at;
                $ageMinutes = $referenceAt ? max(0, (int) now()->diffInMinutes(Carbon::parse($referenceAt), true)) : null;

                return [
                    'id' => (int) $row->id,
                    'public_id' => (string) $row->public_id,
                    'event_id' => (int) $row->event_id,
                    'production_id' => (int) $row->production_id,
                    'event_name' => (string) ($row->event_name ?: 'Evento'),
                    'total' => round((float) $row->total, 2),
                    'paid_at' => $row->paid_at,
                    'updated_at' => $row->updated_at,
                    'expected_passes' => (int) $row->expected_passes,
                    'emitted_passes' => (int) $row->emitted_passes,
                    'missing_passes' => (int) $row->missing_passes,
                    'age_minutes' => $ageMinutes,
                    'sla_breached' => $ageMinutes !== null && $ageMinutes >= $slaMinutes,
                ];
            })
            ->values()
            ->all();

        $unresolvedOrders = (int) ($summary->unresolved_orders ?? 0);
        $oldestUnresolvedAt = $summary->oldest_unresolved_at ?? null;
        $oldestUnresolvedMinutes = $oldestUnresolvedAt
            ? max(0, (int) now()->diffInMinutes(Carbon::parse($oldestUnresolvedAt), true))
            : null;

        return [
            'status' => $unresolvedOrders > 0 ? 'critical' : 'healthy',
            'unresolved_orders' => $unresolvedOrders,
            'unresolved_gmv' => round((float) ($summary->unresolved_gmv ?? 0), 2),
            'missing_passes' => (int) ($summary->missing_passes ?? 0),
            'sla_minutes' => $slaMinutes,
            'over_sla_orders' => (int) $overSlaOrders,
            'oldest_unresolved_at' => $oldestUnresolvedAt,
            'oldest_unresolved_minutes' => $oldestUnresolvedMinutes,
            'requires_attention' => $unresolvedOrders > 0,
            'orders' => $orders,
            'limit' => $limit,
            'measured_at' => now()->toIso8601String(),
        ];
    }
}
