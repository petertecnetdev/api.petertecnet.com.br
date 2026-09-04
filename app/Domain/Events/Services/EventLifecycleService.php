<?php

namespace App\Domain\Events\Services;

use App\Domain\Commerce\Services\CommerceRefundService;
use App\Models\CommerceOrder;
use App\Models\CommerceRefund;
use App\Models\Event;
use App\Models\EventLifecycleAction;
use App\Models\EventPass;
use App\Models\User;
use App\Services\EventAudienceService;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventLifecycleService
{
    public const SCHEDULED = 'scheduled';
    public const POSTPONED = 'postponed';
    public const RESCHEDULED = 'rescheduled';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventAudienceService $audience,
        private readonly CommerceRefundService $refunds,
    ) {}

    public function impact(Event $event): array
    {
        $appId = $this->context->id();
        $orders = CommerceOrder::query()->where('app_id', $appId)->where('event_id', $event->id);
        $paidOrders = (clone $orders)->whereIn('status', ['paid', 'refund_pending', 'refunded']);
        $activePaidOrders = (clone $orders)->whereIn('status', ['paid', 'refund_pending']);
        $passes = EventPass::query()->where('event_id', $event->id);
        $activePasses = (clone $passes)->whereNotIn('status', ['cancelled', 'refunded', 'charged_back']);
        $refunds = CommerceRefund::query()->where('app_id', $appId)->whereIn('order_id', (clone $orders)->select('id'));

        $ordersCount = (clone $orders)->count();
        $issuedCount = (clone $activePasses)->count();

        return [
            'orders_count' => $ordersCount,
            'paid_orders_count' => (clone $paidOrders)->count(),
            'refundable_orders_count' => (clone $activePaidOrders)->where('status', 'paid')->count(),
            'buyers_count' => (clone $paidOrders)->whereNotNull('user_id')->distinct()->count('user_id'),
            'issued_passes_count' => $issuedCount,
            'gross_paid' => round((float) (clone $paidOrders)->sum('total'), 2),
            'refunds' => [
                'pending' => (clone $refunds)->whereIn('status', ['pending', 'processing'])->count(),
                'completed' => (clone $refunds)->where('status', 'completed')->count(),
                'failed' => (clone $refunds)->where('status', 'failed')->count(),
            ],
            'has_commercial_history' => $ordersCount > 0 || $issuedCount > 0,
            'can_delete' => $ordersCount === 0 && (clone $passes)->count() === 0,
        ];
    }

    public function hasCommercialHistory(Event $event): bool
    {
        $impact = $this->impact($event);
        return (bool) $impact['has_commercial_history'];
    }

    public function cancel(Event $event, User $actor, string $reason): array
    {
        if ($event->lifecycle_status === self::CANCELLED || $event->is_cancelled) {
            return ['event' => $event->fresh(), 'impact' => $this->impact($event), 'refunds' => null];
        }

        $previousStatus = $event->lifecycle_status ?: self::SCHEDULED;
        $event = DB::transaction(function () use ($event, $actor, $reason, $previousStatus) {
            $locked = Event::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($event->id);
            abort_if($locked->end_date && now(config('app.timezone'))->gt($locked->end_date), 422, 'Um evento já encerrado não pode ser cancelado por este fluxo.');

            $locked->forceFill([
                'lifecycle_status' => self::CANCELLED,
                'is_cancelled' => true,
                'is_published' => false,
                'sales_paused_at' => $locked->sales_paused_at ?: now(),
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'lifecycle_reason' => $reason,
                'refund_deadline_at' => null,
            ])->save();

            $this->record($locked, $actor, 'cancel', $previousStatus, self::CANCELLED, $reason);
            return $locked->fresh();
        }, 3);

        $impact = $this->impact($event);
        $this->audience->notifyAttendees($event, [
            'type' => 'event_cancelled',
            'title' => 'Evento cancelado',
            'message' => $event->title.' foi cancelado. Se houve pagamento, o reembolso será processado e acompanhado pela plataforma.',
            'data' => ['event_id' => $event->id, 'reason' => $reason],
        ]);

        $refundSummary = $impact['paid_orders_count'] > 0
            ? $this->refunds->refundEvent($event, $actor, $reason)
            : ['eligible' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0];

        return ['event' => $event->fresh(), 'impact' => $this->impact($event), 'refunds' => $refundSummary];
    }

    public function postpone(Event $event, User $actor, string $reason, ?Carbon $refundDeadline = null): Event
    {
        abort_if($event->is_cancelled || $event->lifecycle_status === self::CANCELLED, 422, 'Um evento cancelado não pode ser adiado.');
        abort_if($event->end_date && now(config('app.timezone'))->gt($event->end_date), 422, 'Um evento já encerrado não pode ser adiado.');
        $previousStatus = $event->lifecycle_status ?: self::SCHEDULED;
        $deadline = $refundDeadline ?: $this->defaultRefundDeadline();

        $updated = DB::transaction(function () use ($event, $actor, $reason, $previousStatus, $deadline) {
            $locked = Event::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($event->id);
            $locked->forceFill([
                'lifecycle_status' => self::POSTPONED,
                'sales_paused_at' => $locked->sales_paused_at ?: now(),
                'postponed_at' => now(),
                'postponed_by_user_id' => $actor->id,
                'lifecycle_reason' => $reason,
                'refund_deadline_at' => $deadline,
            ])->save();
            $this->record($locked, $actor, 'postpone', $previousStatus, self::POSTPONED, $reason);
            return $locked->fresh();
        }, 3);

        $this->audience->notifyAttendees($updated, [
            'type' => 'event_postponed',
            'title' => 'Evento adiado',
            'message' => $updated->title.' foi adiado. As vendas foram pausadas e seu ingresso continua registrado. Você pode acompanhar a nova data ou solicitar reembolso dentro da janela informada.',
            'data' => ['event_id' => $updated->id, 'reason' => $reason, 'refund_deadline_at' => $updated->refund_deadline_at?->toIso8601String()],
        ]);

        return $updated;
    }

    public function reschedule(Event $event, User $actor, Carbon $newStart, Carbon $newEnd, string $reason, ?Carbon $refundDeadline = null): Event
    {
        abort_if($event->is_cancelled || $event->lifecycle_status === self::CANCELLED, 422, 'Um evento cancelado não pode ser reagendado.');
        abort_unless($newEnd->gt($newStart), 422, 'O término do evento precisa ser posterior ao início.');
        abort_unless($newStart->gt(now(config('app.timezone'))), 422, 'A nova data precisa estar no futuro.');
        abort_if($newStart->diffInDays($newEnd) > 30, 422, 'A duração do evento não pode ultrapassar 30 dias.');

        $previousStatus = $event->lifecycle_status ?: self::SCHEDULED;
        $previousStart = $event->start_date?->copy();
        $previousEnd = $event->end_date?->copy();
        $deadline = $refundDeadline ?: $this->defaultRefundDeadline();

        $updated = DB::transaction(function () use ($event, $actor, $newStart, $newEnd, $reason, $previousStatus, $previousStart, $previousEnd, $deadline) {
            $locked = Event::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($event->id);
            $locked->forceFill([
                'start_date' => $newStart,
                'end_date' => $newEnd,
                'lifecycle_status' => self::RESCHEDULED,
                'sales_paused_at' => null,
                'rescheduled_at' => now(),
                'rescheduled_by_user_id' => $actor->id,
                'previous_start_date' => $previousStart,
                'previous_end_date' => $previousEnd,
                'lifecycle_reason' => $reason,
                'refund_deadline_at' => $deadline,
            ])->save();

            EventLifecycleAction::query()->create([
                'app_id' => $this->context->id(),
                'event_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'action' => 'reschedule',
                'from_status' => $previousStatus,
                'to_status' => self::RESCHEDULED,
                'reason' => $reason,
                'previous_start_date' => $previousStart,
                'previous_end_date' => $previousEnd,
                'new_start_date' => $newStart,
                'new_end_date' => $newEnd,
                'metadata' => ['refund_deadline_at' => $deadline?->toIso8601String()],
            ]);

            return $locked->fresh();
        }, 3);

        $this->audience->notifyAttendees($updated, [
            'type' => 'event_rescheduled',
            'title' => 'Nova data do evento',
            'message' => $updated->title.' ganhou uma nova data. Seu ingresso continua válido automaticamente. Se a nova data não funcionar para você, solicite reembolso dentro da janela informada.',
            'data' => [
                'event_id' => $updated->id,
                'previous_start_date' => $previousStart?->toIso8601String(),
                'new_start_date' => $updated->start_date?->toIso8601String(),
                'refund_deadline_at' => $updated->refund_deadline_at?->toIso8601String(),
                'reason' => $reason,
            ],
        ]);

        return $updated;
    }

    public function history(Event $event): array
    {
        return EventLifecycleAction::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->with('actor:id,first_name,last_name,user_name,email')
            ->latest('id')
            ->limit(100)
            ->get()
            ->toArray();
    }

    private function record(Event $event, User $actor, string $action, string $from, string $to, string $reason): void
    {
        EventLifecycleAction::query()->create([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'previous_start_date' => $event->previous_start_date,
            'previous_end_date' => $event->previous_end_date,
            'new_start_date' => $event->start_date,
            'new_end_date' => $event->end_date,
            'metadata' => ['refund_deadline_at' => $event->refund_deadline_at?->toIso8601String()],
        ]);
    }

    private function defaultRefundDeadline(): Carbon
    {
        $days = max(1, min((int) $this->context->option('events.reschedule_refund_window_days', 7), 30));
        return now(config('app.timezone'))->addDays($days);
    }
}
