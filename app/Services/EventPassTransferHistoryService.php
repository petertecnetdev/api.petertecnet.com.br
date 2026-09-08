<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class EventPassTransferHistoryService
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function mine(User $user): array
    {
        $transfers = DB::table('event_pass_transfers')
            ->where('from_user_id', $user->id)
            ->orderByDesc('transferred_at')
            ->get();

        if ($transfers->isEmpty()) {
            return [];
        }

        $appId = $this->context->id();
        $eventIds = $transfers->pluck('event_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $ticketIds = $transfers->pluck('ticket_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $events = Event::query()
            ->where('app_id', $appId)
            ->whereIn('id', $eventIds)
            ->get()
            ->keyBy('id');

        $tickets = Ticket::query()
            ->where('app_id', $appId)
            ->whereIn('id', $ticketIds)
            ->get()
            ->keyBy('id');

        return $transfers
            ->map(function ($transfer) use ($events, $tickets) {
                $event = $events->get((int) $transfer->event_id);
                if (! $event) {
                    return null;
                }

                $ticket = $tickets->get((int) $transfer->ticket_id);

                return [
                    'id' => (int) $transfer->id,
                    'reference' => 'TRF-'.str_pad((string) $transfer->id, 8, '0', STR_PAD_LEFT),
                    'pass_id' => (int) $transfer->event_pass_id,
                    'event_id' => (int) $transfer->event_id,
                    'ticket_id' => (int) $transfer->ticket_id,
                    'recipient_id' => (int) $transfer->to_user_id,
                    'recipient_name' => (string) ($transfer->to_holder_name ?: $transfer->to_holder_email),
                    'recipient_email' => (string) $transfer->to_holder_email,
                    'sender_name' => (string) ($transfer->from_holder_name ?: $transfer->from_holder_email),
                    'status' => 'completed',
                    'transferred_at' => $transfer->transferred_at,
                    'event' => [
                        'id' => (int) $event->id,
                        'title' => (string) ($event->title ?: 'Evento'),
                        'slug' => $event->slug,
                        'start_date' => $event->start_date,
                        'end_date' => $event->end_date,
                        'venue' => $event->venue,
                        'city' => $event->city,
                        'uf' => $event->uf,
                        'is_cancelled' => (bool) $event->is_cancelled,
                    ],
                    'ticket' => $ticket ? [
                        'id' => (int) $ticket->id,
                        'name' => (string) ($ticket->name ?: 'Ingresso Cutinapp'),
                        'ticket_type' => $ticket->getAttribute('ticket_type') ?: $ticket->getAttribute('type'),
                    ] : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
