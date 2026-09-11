<?php

namespace App\Services;

use App\Mail\EventPassesMail;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EventAudienceService
{
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

    public function interestedUserIds(Event $event): Collection
    {
        return DB::table('event_engagements')
            ->where('app_id', $event->app_id)
            ->where('event_id', $event->id)
            ->where('is_interested', true)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
    }

    public function productionFollowerUserIds(Event $event): Collection
    {
        $productionId = (int) $event->production_id;
        if ($productionId <= 0) {
            return collect();
        }

        return DB::table('follows')
            ->where('app_id', $event->app_id)
            ->where('target_type', 'production')
            ->where('target_id', $productionId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
    }

    public function markInterested(int $appId, int $eventId, int $userId): void
    {
        DB::table('event_engagements')->updateOrInsert(
            ['app_id' => $appId, 'user_id' => $userId, 'event_id' => $eventId],
            ['is_interested' => true, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function notifyAttendees(Event $event, array $payload, ?int $excludeUserId = null): void
    {
        $this->notifyUsers($event, $this->attendeeUserIds($event), $payload, $excludeUserId);
    }

    public function notifyProductionFollowers(Event $event, array $payload, ?int $excludeUserId = null): void
    {
        $this->notifyUsers($event, $this->productionFollowerUserIds($event), $payload, $excludeUserId);
    }

    public function notifyEngagedAudience(Event $event, array $payload, ?int $excludeUserId = null): void
    {
        $userIds = $this->attendeeUserIds($event)
            ->merge($this->interestedUserIds($event))
            ->merge($this->productionFollowerUserIds($event))
            ->unique()
            ->values();

        $this->notifyUsers($event, $userIds, $payload, $excludeUserId);
    }

    public function notifyInterested(Event $event, array $payload, ?int $excludeUserId = null): void
    {
        $this->notifyUsers($event, $this->interestedUserIds($event), $payload, $excludeUserId);
    }

    public function confirmPaidOrder(int $orderId): void
    {
        $order = CommerceOrder::query()->with(['event.application', 'user', 'items'])->find($orderId);
        if (! $order || $order->status !== 'paid' || ! $order->event || ! $order->user) {
            return;
        }

        $ticketQuantity = (int) $order->items->where('type', 'ticket')->sum('quantity');
        if ($ticketQuantity <= 0) {
            return;
        }

        $appId = (int) $order->event->app_id;
        $delivery = app(DeliveryEffectService::class);
        $deliveryContext = [
            'order_id' => (int) $order->id,
            'event_id' => (int) $order->event_id,
            'user_id' => (int) $order->user_id,
            'ticket_count' => $ticketQuantity,
        ];

        $this->markInterested($appId, (int) $order->event_id, (int) $order->user_id);

        $passes = EventPass::query()
            ->where('event_id', $order->event_id)
            ->where('user_id', $order->user_id)
            ->whereIn('commerce_order_item_id', $order->items->where('type', 'ticket')->pluck('id'))
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->with('ticket')
            ->orderBy('id')
            ->get();

        $delivery->run(
            $appId,
            'commerce_order',
            (int) $order->id,
            'buyer_ticket_notification',
            function () use ($appId, $order, $ticketQuantity) {
                app(AppNotificationService::class)->sendToUser($appId, (int) $order->user_id, [
                    'type' => 'ticket_purchase_confirmed',
                    'title' => 'Presença confirmada',
                    'message' => 'Sua compra foi aprovada. Você tem '.$ticketQuantity.' '.($ticketQuantity === 1 ? 'ingresso' : 'ingressos').' para '.$order->event->title.'.',
                    'reference_type' => 'event',
                    'reference_id' => $order->event_id,
                    'reference_url' => '/passes',
                    'send_email' => false,
                    'data' => ['event_id' => $order->event_id, 'order_id' => $order->id, 'ticket_count' => $ticketQuantity],
                ]);
            },
            $deliveryContext
        );

        if ($passes->isNotEmpty() && $order->user->email) {
            try {
                $delivery->run(
                    $appId,
                    'commerce_order',
                    (int) $order->id,
                    'buyer_event_passes_email',
                    function () use ($order, $passes) {
                        Mail::to($order->user->email)->send(new EventPassesMail($order, $passes));
                    },
                    $deliveryContext
                );
            } catch (\Throwable $e) {
                Log::error('Falha ao enviar ingressos por e-mail; entrega ficará pendente para nova reconciliação.', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        app(ImportantEventService::class)->recordTicketPurchase($order);
    }

    public function confirmCourtesy(EventPass $pass): void
    {
        if (! $pass->user_id) {
            return;
        }

        $event = $pass->event()->first();
        if (! $event) {
            return;
        }

        $this->markInterested((int) $event->app_id, (int) $event->id, (int) $pass->user_id);
    }

    public function notifyNewTicket(Ticket $ticket): void
    {
        $event = $ticket->event()->first();
        if (! $event || ! $event->is_published || $event->is_cancelled || $event->is_private) {
            return;
        }

        $userIds = $this->attendeeUserIds($event)
            ->merge($this->interestedUserIds($event))
            ->merge($this->productionFollowerUserIds($event))
            ->unique()
            ->values();

        $this->notifyUsers($event, $userIds, [
            'type' => 'event_ticket_added',
            'title' => 'Novo ingresso disponível',
            'message' => 'Um novo ingresso, '.$ticket->name.', foi adicionado a '.$event->title.'.',
            'data' => ['event_id' => $event->id, 'ticket_id' => $ticket->id],
        ]);
    }

    private function notifyUsers(Event $event, Collection $userIds, array $payload, ?int $excludeUserId = null): void
    {
        $userIds = $userIds
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && ($excludeUserId === null || $id !== $excludeUserId))
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        app(AppNotificationService::class)->sendToUsers(
            (int) $event->app_id,
            $userIds,
            array_merge([
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/event/'.$event->slug,
                'data' => ['event_id' => $event->id],
            ], $payload),
            $excludeUserId
        );
    }
}
