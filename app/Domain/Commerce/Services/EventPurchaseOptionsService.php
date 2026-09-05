<?php

namespace App\Domain\Commerce\Services;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\EventPass;
use App\Models\Ticket;
use App\Services\MerchantPaymentAccountService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class EventPurchaseOptionsService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MerchantPaymentAccountService $accounts,
    ) {}

    public function forSlug(string $slug): array
    {
        $event = $this->publicEvent($slug);

        $tickets = Ticket::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('price', '>', 0)
            ->orderBy('price')
            ->get()
            ->map(function (Ticket $ticket) {
                $issued = EventPass::query()
                    ->where('ticket_id', $ticket->id)
                    ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
                    ->count();
                $reserved = (int) DB::table('inventory_reservations')
                    ->where('app_id', $this->context->id())
                    ->where('ticket_id', $ticket->id)
                    ->whereNull('released_at')
                    ->where('expires_at', '>', now())
                    ->sum('quantity');
                $remaining = max(0, (int) $ticket->quantity - $issued - $reserved);
                $expired = (bool) ($ticket->limit_date && now()->greaterThan($ticket->limit_date));

                return array_merge($ticket->toArray(), [
                    'remaining' => $remaining,
                    'expired' => $expired,
                    'available' => ! $expired && $remaining > 0,
                ]);
            })->values();

        $items = EventItem::query()
            ->with('sourceItem.files')
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (EventItem $item) {
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
                $source = $item->sourceItem;
                if ($source) $source->setAppends(['image_url']);

                return array_merge($item->toArray(), [
                    'remaining' => $remaining,
                    'available' => $remaining > 0,
                    'image_url' => $source?->image_url,
                    'catalog_source' => $source ? [
                        'id' => $source->id,
                        'sku' => $source->sku,
                        'category' => $source->category,
                        'brand' => $source->brand,
                    ] : null,
                ]);
            })->values();

        $readiness = $this->accounts->readiness((int) $event->production_id);

        return [
            'event' => $event->only(['id', 'title', 'slug', 'start_date', 'end_date', 'event_series_id', 'event_schedule_id', 'event_schedule_occurrence_date']),
            'available_dates' => $this->availableDates($event),
            'tickets' => $tickets,
            'items' => $items,
            'payment_config' => [
                'provider' => 'mercadopago',
                'connected' => $readiness['available'],
                'available' => $readiness['available'],
                'merchant_connected' => $readiness['merchant_connected'],
                'settlement_mode' => $readiness['settlement_mode'],
                'public_key' => $readiness['public_key'],
                'methods' => $readiness['methods'],
                'message' => $readiness['message'],
            ],
        ];
    }

    private function publicEvent(string $slug): Event
    {
        return Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->whereHas('production', fn ($query) => $query->where('app_id', $this->context->id()))
            ->firstOrFail();
    }

    private function availableDates(Event $event): array
    {
        $query = Event::query()
            ->where('app_id', $this->context->id())
            ->where('production_id', $event->production_id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>', now());
            });

        if ($event->event_series_id) {
            $query->where('event_series_id', $event->event_series_id);
        } elseif ($event->event_schedule_id) {
            $query->where('event_schedule_id', $event->event_schedule_id);
        } else {
            $query->whereKey($event->id);
        }

        return $query->orderBy('start_date')->get()->map(function (Event $dateEvent) {
            return [
                'event_id' => $dateEvent->id,
                'slug' => $dateEvent->slug,
                'series_id' => $dateEvent->event_series_id,
                'date' => $dateEvent->event_schedule_occurrence_date ?: optional($dateEvent->start_date)->toDateString(),
                'start_date' => $dateEvent->start_date,
                'end_date' => $dateEvent->end_date,
                'title' => $dateEvent->title,
            ];
        })->values()->all();
    }
}
