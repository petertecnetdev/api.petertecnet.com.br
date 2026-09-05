<?php

namespace App\Domain\Events\Services;

use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EventAttendanceMetricsService
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function forManagedEvent(int $appId, int $eventId, User $operator): array
    {
        $event = Event::query()
            ->where('app_id', $appId)
            ->with('production')
            ->findOrFail($eventId);

        abort_unless(
            $event->production && (int) $event->production->app_id === $appId,
            404,
            'Evento não encontrado neste contexto.'
        );

        abort_unless(
            $this->canOperateEvent($operator, $event, $appId),
            403,
            'Sem permissão para acessar a operação deste evento.'
        );

        $validPasses = EventPass::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES);

        $issued = (clone $validPasses)->count();
        $checkedIn = (clone $validPasses)->whereNotNull('checked_in_at')->count();
        $attendanceFinalized = $event->end_date !== null && now()->greaterThanOrEqualTo($event->end_date);
        $noShow = $attendanceFinalized
            ? (clone $validPasses)->whereNull('checked_in_at')->count()
            : 0;

        $stats = [
            'event' => $event->only([
                'id',
                'title',
                'slug',
                'is_published',
                'is_cancelled',
                'start_date',
                'end_date',
            ]),
            'attendance_finalized' => $attendanceFinalized,
            'issued' => $issued,
            'checked_in' => $checkedIn,
            'no_show' => $noShow,
            'no_show_rate' => $attendanceFinalized && $issued > 0
                ? round(($noShow / $issued) * 100, 2)
                : 0.0,
        ];

        if (! $this->canViewFinancials($operator, $event)) {
            return $stats;
        }

        $paidPasses = (clone $validPasses)
            ->whereHas('orderItem.order', function ($query) use ($appId, $event) {
                $query
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('status', 'paid');
            });

        $paid = (clone $paidPasses)->count();
        $paidNoShow = $attendanceFinalized
            ? (clone $paidPasses)->whereNull('checked_in_at')->count()
            : 0;

        $paidNoShowFaceValue = 0.0;
        if ($attendanceFinalized && $paidNoShow > 0) {
            $paidNoShowFaceValue = (float) DB::table('event_passes as ep')
                ->join('commerce_order_items as oi', 'oi.id', '=', 'ep.commerce_order_item_id')
                ->join('commerce_orders as o', 'o.id', '=', 'oi.order_id')
                ->where('ep.event_id', $event->id)
                ->where('oi.app_id', $appId)
                ->where('o.app_id', $appId)
                ->where('o.event_id', $event->id)
                ->where('o.status', 'paid')
                ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES)
                ->whereNull('ep.checked_in_at')
                ->sum('oi.unit_price');
        }

        $paidOrders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('status', 'paid');

        $stats['paid'] = $paid;
        $stats['paid_no_show'] = $paidNoShow;
        $stats['paid_no_show_rate'] = $attendanceFinalized && $paid > 0
            ? round(($paidNoShow / $paid) * 100, 2)
            : 0.0;
        $stats['paid_no_show_face_value'] = round($paidNoShowFaceValue, 2);
        $stats['financial'] = [
            'paid_orders' => (clone $paidOrders)->count(),
            'gross_sales' => round((float) (clone $paidOrders)->sum('total'), 2),
            'platform_revenue' => round((float) (clone $paidOrders)->sum('platform_fee'), 2),
            'processor_fees' => round((float) (clone $paidOrders)->sum('processor_fee'), 2),
            'producer_net' => round((float) (clone $paidOrders)->sum('producer_net'), 2),
            'revenue_recognition' => 'paid_order',
            'no_show_extra_fee_applied' => false,
        ];

        return $stats;
    }

    private function canViewFinancials(User $operator, Event $event): bool
    {
        return $operator->hasProfile('Administrador')
            || ($event->production && (int) $event->production->user_id === (int) $operator->id);
    }

    private function canOperateEvent(User $operator, Event $event, int $appId): bool
    {
        if ($this->canViewFinancials($operator, $event)) {
            return true;
        }

        if (! $operator->hasPermission('ticket_checkin') && ! $operator->hasPermission('event_checkin')) {
            return false;
        }

        $membership = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $operator->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return false;
        }

        $metadata = $membership->metadata ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true);
        } elseif (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        if (! is_array($metadata)) {
            return false;
        }

        $eventIds = array_map('intval', is_array($metadata['event_ids'] ?? null) ? $metadata['event_ids'] : []);
        $productionIds = array_map('intval', is_array($metadata['production_ids'] ?? null) ? $metadata['production_ids'] : []);

        return in_array((int) $event->id, $eventIds, true)
            || ($event->production && in_array((int) $event->production->id, $productionIds, true));
    }
}
