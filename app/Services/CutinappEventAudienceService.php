<?php

namespace App\Services;

use App\Mail\CutinappTicketsMail;
use App\Models\Application;
use App\Models\CutinappOrder;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CutinappEventAudienceService
{
    private const APP = 'cutinapp';

    public function attendeeUserIds(Event|int $event): Collection
    {
        $eventId = $event instanceof Event ? $event->id : $event;

        return EventPass::query()
            ->where('event_id', $eventId)
            ->whereNotNull('user_id')
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function markInterested(int $appId, int $eventId, int $userId): void
    {
        DB::table('cutinapp_event_engagements')->updateOrInsert(
            ['app_id' => $appId, 'user_id' => $userId, 'event_id' => $eventId],
            ['is_interested' => true, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function notifyAttendees(Event $event, array $payload, ?int $excludeUserId = null): void
    {
        if ($event->app_slug !== self::APP) return;

        $userIds = $this->attendeeUserIds($event);
        if ($userIds->isEmpty()) return;

        app(AppNotificationService::class)->sendToUsers(
            (int) $event->app_id,
            $userIds,
            array_merge([
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/event/' . $event->slug,
                'data' => ['event_id' => $event->id],
            ], $payload),
            $excludeUserId
        );
    }

    public function confirmPaidOrder(int $orderId): void
    {
        $order = CutinappOrder::query()
            ->with(['event', 'user', 'items'])
            ->find($orderId);

        if (! $order || $order->status !== 'paid' || ! $order->event || ! $order->user) return;

        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->first();
        if (! $application || (int) $order->event->app_id !== (int) $application->id) return;

        $ticketQuantity = (int) $order->items->where('type', 'ticket')->sum('quantity');
        if ($ticketQuantity <= 0) return;

        $this->markInterested((int) $application->id, (int) $order->event_id, (int) $order->user_id);

        $passes = EventPass::query()
            ->where('event_id', $order->event_id)
            ->where('user_id', $order->user_id)
            ->whereIn('cutinapp_order_item_id', $order->items->where('type', 'ticket')->pluck('id'))
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->with('ticket')
            ->orderBy('id')
            ->get();

        app(AppNotificationService::class)->sendToUser((int) $application->id, (int) $order->user_id, [
            'type' => 'ticket_purchase_confirmed',
            'title' => 'Presença confirmada',
            'message' => 'Sua compra foi aprovada. Você tem ' . $ticketQuantity . ' ' . ($ticketQuantity === 1 ? 'ingresso' : 'ingressos') . ' para ' . $order->event->title . ' e já marcou interesse no evento.',
            'reference_type' => 'event',
            'reference_id' => $order->event_id,
            'reference_url' => '/passes',
            'data' => ['event_id' => $order->event_id, 'order_id' => $order->id, 'ticket_count' => $ticketQuantity],
        ]);

        if ($passes->isNotEmpty() && $order->user->email) {
            try {
                Mail::to($order->user->email)->send(new CutinappTicketsMail($order, $passes));
            } catch (\Throwable $e) {
                Log::error('Falha ao enviar ingressos da Cutinapp por e-mail.', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function confirmCourtesy(EventPass $pass): void
    {
        if (! $pass->user_id) return;
        $event = $pass->event()->first();
        if (! $event || $event->app_slug !== self::APP) return;

        $this->markInterested((int) $event->app_id, (int) $event->id, (int) $pass->user_id);
    }

    public function notifyNewTicket(Ticket $ticket): void
    {
        $event = $ticket->event()->first();
        if (! $event || ! $event->is_published || $event->is_cancelled) return;

        $this->notifyAttendees($event, [
            'type' => 'event_ticket_added',
            'title' => 'Novo ingresso disponível',
            'message' => 'Um novo ingresso, ' . $ticket->name . ', foi adicionado a ' . $event->title . '.',
            'data' => ['event_id' => $event->id, 'ticket_id' => $ticket->id],
        ]);
    }
}
