<?php

namespace App\Domain\Commerce\Services;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\Ticket;
use App\Services\MerchantPaymentAccountService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class EventPurchaseOptionsService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MerchantPaymentAccountService $accounts,
        private readonly TicketInventoryService $ticketInventory,
    ) {}

    public function forSlug(string $slug): array
    {
        $event = $this->publicEvent($slug);
        $salesClosed = $event->salesClosed();

        $ticketModels = Ticket::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('price', '>', 0)
            ->orderBy('price')
            ->get();
        $ticketStates = $this->ticketInventory->states($ticketModels);
        $tickets = $ticketModels->map(function (Ticket $ticket) use ($salesClosed, $ticketStates) {
            $state = $ticketStates->get((int) $ticket->id, ['remaining' => 0, 'expired' => true, 'available' => false]);
            if ($salesClosed) {
                $state['expired'] = true;
                $state['available'] = false;
            }

            return array_merge($ticket->setAppends([])->toArray(), $state);
        })->values();

        $items = EventItem::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (EventItem $item) use ($salesClosed) {
                $reserved = (int) DB::table('inventory_reservations')
                    ->where('app_id', $this->context->id())
                    ->where('event_item_id', $item->id)
                    ->whereNull('released_at')
                    ->where('expires_at', '>', now())
                    ->sum('quantity');
                $sold = (int) DB::table('commerce_order_items as oi')
                    ->join('commerce_orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('oi.app_id', $this->context->id())
                    ->where('o.app_id', $this->context->id())
                    ->where('oi.event_item_id', $item->id)
                    ->where('o.status', 'paid')
                    ->sum('oi.quantity');
                $remaining = max(0, (int) $item->quantity - $sold - $reserved);

                return array_merge($item->toArray(), [
                    'remaining' => $remaining,
                    'available' => ! $salesClosed && $remaining > 0,
                ]);
            })->values();

        $readiness = $this->accounts->readiness((int) $event->production_id);
        $paymentAvailable = ! $salesClosed && $readiness['available'];

        return [
            'event' => $event->only(['id', 'title', 'slug', 'start_date', 'end_date', 'event_schedule_id', 'event_schedule_occurrence_date']),
            'sales_closed' => $salesClosed,
            'available_dates' => $this->availableDates($event),
            'tickets' => $tickets,
            'items' => $items,
            'payment_config' => [
                'provider' => 'mercadopago',
                'connected' => $readiness['available'],
                'available' => $paymentAvailable,
                'merchant_connected' => $readiness['merchant_connected'],
                'settlement_mode' => $readiness['settlement_mode'],
                'public_key' => $readiness['public_key'],
                'methods' => $paymentAvailable ? $readiness['methods'] : [],
                'message' => $salesClosed ? 'As vendas deste evento foram encerradas.' : $readiness['message'],
            ],
        ];
    }

    private function publicEvent(string $slug): Event
    {
        return Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->publiclyVisible()
            ->whereHas('production', fn ($query) => $query->where('app_id', $this->context->id()))
            ->firstOrFail();
    }

    private function availableDates(Event $event): array
    {
        $query = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $event->production_id)
            ->publiclyVisible()
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>', now());
            });

        if ($event->event_schedule_id) {
            $query->where('event_schedule_id', $event->event_schedule_id);
        } else {
            $query->whereKey($event->id);
        }

        return $query->orderBy('start_date')->get()->map(function (Event $dateEvent) {
            return [
                'event_id' => $dateEvent->id,
                'slug' => $dateEvent->slug,
                'date' => $dateEvent->event_schedule_occurrence_date ?: optional($dateEvent->start_date)->toDateString(),
                'start_date' => $dateEvent->start_date,
                'end_date' => $dateEvent->end_date,
                'title' => $dateEvent->title,
            ];
        })->values()->all();
    }
}
