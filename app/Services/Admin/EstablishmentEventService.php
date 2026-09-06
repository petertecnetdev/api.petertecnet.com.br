<?php

namespace App\Services\Admin;

use App\Models\Establishment;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EstablishmentEventService
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(private readonly EstablishmentEventTicketAnalyticsService $ticketAnalytics)
    {
    }

    public function listing(Establishment $establishment, ?int $appId = null): array
    {
        $events = Event::query()
            ->select([
                'id',
                'app_id',
                'production_id',
                'title',
                'slug',
                'start_date',
                'end_date',
                'venue',
                'city',
                'uf',
                'is_published',
                'is_approved',
                'is_cancelled',
                'is_private',
            ])
            ->where('production_id', $establishment->id)
            ->when($appId !== null, fn ($query) => $query->where('app_id', $appId))
            ->withCount('artists')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return [
            'establishment' => $this->establishmentPayload($establishment),
            'events' => $this->ticketAnalytics->attachSummaries($events),
        ];
    }

    public function ticketDetails(Establishment $establishment, Event $event, ?int $appId = null): array
    {
        $this->assertEventContext($establishment, $event, $appId);

        return [
            'establishment' => $this->establishmentPayload($establishment),
            'event' => $this->eventPayload($event),
            ...$this->ticketAnalytics->eventTicketDetails($event),
        ];
    }

    public function createTicket(Establishment $establishment, Event $event, array $data, ?int $appId = null): array
    {
        $this->assertEventContext($establishment, $event, $appId);

        $ticket = DB::transaction(function () use ($event, $data) {
            return Ticket::query()->create([
                'app_id' => $event->app_id,
                'event_id' => $event->id,
                'name' => trim($data['name']),
                'ticket_type' => trim($data['ticket_type']),
                'type' => isset($data['type']) && trim((string) $data['type']) !== '' ? trim((string) $data['type']) : null,
                'price' => $data['price'],
                'quantity' => $data['quantity'],
                'limit_date' => $data['limit_date'] ?? null,
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            ]);
        });

        return [
            'message' => 'Lote de ingresso criado com sucesso.',
            'ticket' => $ticket->fresh(),
            ...$this->ticketAnalytics->eventTicketDetails($event->fresh()),
        ];
    }

    public function updateTicket(Establishment $establishment, Event $event, Ticket $ticket, array $data, ?int $appId = null): array
    {
        $this->assertEventContext($establishment, $event, $appId);
        abort_unless((int) $ticket->event_id === (int) $event->id, 404);

        if (array_key_exists('quantity', $data)) {
            $issued = Ticket::query()
                ->findOrFail($ticket->id)
                ->passes()
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->count();
            $reserved = (int) DB::table('inventory_reservations')
                ->where('ticket_id', $ticket->id)
                ->whereNull('released_at')
                ->where('expires_at', '>', now())
                ->sum('quantity');
            $minimumCapacity = $issued + $reserved;

            if ((int) $data['quantity'] < $minimumCapacity) {
                throw ValidationException::withMessages([
                    'quantity' => ["A capacidade não pode ser menor que {$minimumCapacity}, pois já existem ingressos emitidos ou reservados."],
                ]);
            }
        }

        DB::transaction(function () use ($ticket, $data) {
            $ticket->fill([
                ...array_key_exists('name', $data) ? ['name' => trim($data['name'])] : [],
                ...array_key_exists('ticket_type', $data) ? ['ticket_type' => trim($data['ticket_type'])] : [],
                ...array_key_exists('type', $data) ? ['type' => trim((string) ($data['type'] ?? '')) ?: null] : [],
                ...array_key_exists('price', $data) ? ['price' => $data['price']] : [],
                ...array_key_exists('quantity', $data) ? ['quantity' => $data['quantity']] : [],
                ...array_key_exists('limit_date', $data) ? ['limit_date' => $data['limit_date']] : [],
                ...array_key_exists('description', $data) ? ['description' => trim((string) ($data['description'] ?? '')) ?: null] : [],
            ]);
            $ticket->save();
        });

        return [
            'message' => 'Lote de ingresso atualizado com sucesso.',
            'ticket' => $ticket->fresh(),
            ...$this->ticketAnalytics->eventTicketDetails($event->fresh()),
        ];
    }

    private function assertEventContext(Establishment $establishment, Event $event, ?int $appId): void
    {
        abort_unless((int) $event->production_id === (int) $establishment->id, 404);
        if ($appId !== null && (int) $event->app_id !== $appId) {
            abort(404);
        }
    }

    private function eventPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'app_id' => $event->app_id,
            'production_id' => $event->production_id,
            'title' => $event->title,
            'slug' => $event->slug,
            'start_date' => $event->start_date?->toIso8601String(),
            'end_date' => $event->end_date?->toIso8601String(),
            'venue' => $event->venue,
            'city' => $event->city,
            'uf' => $event->uf,
            'is_published' => (bool) $event->is_published,
            'is_cancelled' => (bool) $event->is_cancelled,
        ];
    }

    private function establishmentPayload(Establishment $establishment): array
    {
        return [
            'id' => $establishment->id,
            'name' => $establishment->name,
            'fantasy' => $establishment->fantasy,
            'slug' => $establishment->slug,
            'city' => $establishment->city,
            'uf' => $establishment->uf,
        ];
    }
}
