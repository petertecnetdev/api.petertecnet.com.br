<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EventTicketService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function listForEvent(mixed $user, int $eventId): array
    {
        $event = $this->ownedEvent($user, $eventId);

        return [
            'event' => $event->only(['id', 'title', 'slug', 'is_published', 'is_cancelled']),
            'tickets' => Ticket::query()
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->withCount('passes')
                ->orderBy('created_at')
                ->get(),
        ];
    }

    public function createForEvents(mixed $user, array $data): array
    {
        $eventIds = collect($data['event_ids'] ?? [(int) $data['event_id']])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $sourceTicket = isset($data['source_ticket_id'])
            ? $this->ownedTicket($user, (int) $data['source_ticket_id'])
            : null;

        $result = DB::transaction(function () use ($user, $data, $eventIds, $sourceTicket) {
            $created = collect();
            $linked = collect();
            $skipped = collect();
            $adjustedLimitDates = collect();
            $shouldDeduplicate = $sourceTicket !== null || $eventIds->count() > 1;

            foreach ($eventIds as $eventId) {
                $event = $this->ownedEvent($user, $eventId);
                abort_if($event->is_cancelled, 422, "Não é possível criar ingressos para o evento cancelado {$event->title}.");

                if ($sourceTicket && (int) $sourceTicket->event_id === (int) $event->id) {
                    $linked->push($sourceTicket->fresh()->loadCount('passes'));
                    continue;
                }

                [$payload, $limitAdjusted] = $this->payloadForEvent($data, $sourceTicket, $event);
                $equivalent = $shouldDeduplicate
                    ? $this->findEquivalentTicket($event, $payload)
                    : null;

                if ($equivalent) {
                    $skipped->push($equivalent->loadCount('passes'));
                    continue;
                }

                $ticket = Ticket::create($payload)->loadCount('passes');
                $created->push($ticket);

                if ($limitAdjusted) {
                    $adjustedLimitDates->push([
                        'event_id' => $event->id,
                        'ticket_id' => $ticket->id,
                    ]);
                }
            }

            return compact('created', 'linked', 'skipped', 'adjustedLimitDates');
        }, 3);

        $tickets = collect()
            ->concat($result['created'])
            ->concat($result['linked'])
            ->concat($result['skipped'])
            ->unique('id')
            ->values();

        $firstTicket = $tickets->first();
        $isBulk = $eventIds->count() > 1 || $sourceTicket !== null;
        $existingCount = $result['linked']->count() + $result['skipped']->count();

        return [
            'status' => $result['created']->isNotEmpty() ? 201 : 200,
            'payload' => [
                'message' => $isBulk
                    ? sprintf(
                        'Ingresso aplicado a %d evento(s): %d criado(s), %d já existente(s).',
                        $tickets->count(),
                        $result['created']->count(),
                        $existingCount
                    )
                    : ((float) optional($firstTicket)->price > 0 ? 'Ingresso criado com sucesso.' : 'Cortesia criada com sucesso.'),
                'ticket' => $firstTicket,
                'tickets' => $tickets,
                'created_count' => $result['created']->count(),
                'existing_count' => $existingCount,
                'adjusted_limit_dates' => $result['adjustedLimitDates'],
            ],
        ];
    }

    public function updateTicket(mixed $user, int $ticketId, array $data): Ticket
    {
        return DB::transaction(function () use ($user, $ticketId, $data) {
            $ticket = $this->ownedTicket($user, $ticketId, true);
            $issued = $ticket->passes()->count();

            if (isset($data['quantity'])) {
                abort_if((int) $data['quantity'] < $issued, 422, "A quantidade não pode ser menor que os {$issued} ingressos já emitidos.");
            }

            if (array_key_exists('price', $data)) {
                $price = round((float) $data['price'], 2);
                $data['type'] = $price > 0 ? 'paid' : 'courtesy';
                if ($price <= 0) {
                    $data['ticket_type'] = 'courtesy';
                }
            }

            $ticket->update($data);

            return $ticket->fresh()->loadCount('passes');
        }, 3);
    }

    public function deleteTicket(mixed $user, int $ticketId): void
    {
        DB::transaction(function () use ($user, $ticketId) {
            $ticket = $this->ownedTicket($user, $ticketId, true);
            if ($ticket->passes()->exists()) {
                abort(409, 'Este ingresso já possui emissões e não pode ser excluído.');
            }
            $ticket->delete();
        }, 3);
    }

    private function payloadForEvent(array $data, ?Ticket $sourceTicket, Event $event): array
    {
        if ($sourceTicket) {
            $price = round((float) $sourceTicket->price, 2);
            [$limitDate, $limitAdjusted] = $this->cloneLimitDate($sourceTicket, $event);

            return [[
                'app_id' => $this->context->id(),
                'app_slug' => $this->context->slug(),
                'event_id' => $event->id,
                'name' => trim((string) $sourceTicket->name),
                'ticket_type' => $price > 0 ? ($sourceTicket->ticket_type ?: 'standard') : 'courtesy',
                'type' => $price > 0 ? 'paid' : 'courtesy',
                'price' => $price,
                'quantity' => (int) $sourceTicket->quantity,
                'limit_date' => $limitDate,
                'description' => $sourceTicket->description,
            ], $limitAdjusted];
        }

        $price = round((float) $data['price'], 2);
        $paid = $price > 0;

        return [[
            'app_id' => $this->context->id(),
            'app_slug' => $this->context->slug(),
            'event_id' => $event->id,
            'name' => trim((string) $data['name']),
            'ticket_type' => $paid ? ($data['ticket_type'] ?? 'standard') : 'courtesy',
            'type' => $paid ? 'paid' : 'courtesy',
            'price' => $price,
            'quantity' => (int) $data['quantity'],
            'limit_date' => $data['limit_date'] ?? null,
            'description' => $data['description'] ?? null,
        ], false];
    }

    private function cloneLimitDate(Ticket $sourceTicket, Event $targetEvent): array
    {
        if (! $sourceTicket->limit_date) {
            return [null, false];
        }

        $sourceLimit = Carbon::parse($sourceTicket->limit_date);
        $minimum = now()->addHour();
        $targetStart = $targetEvent->start_date ? Carbon::parse($targetEvent->start_date) : null;

        if ($sourceLimit->gte($minimum) && (! $targetStart || $sourceLimit->lte($targetStart))) {
            return [$sourceLimit, false];
        }

        $sourceStart = optional($sourceTicket->event)->start_date
            ? Carbon::parse($sourceTicket->event->start_date)
            : null;

        if ($sourceStart && $targetStart && $sourceLimit->lte($sourceStart)) {
            $secondsBeforeStart = $sourceLimit->diffInSeconds($sourceStart);
            $adapted = $targetStart->copy()->subSeconds($secondsBeforeStart);

            if ($adapted->gte($minimum) && $adapted->lte($targetStart)) {
                return [$adapted, true];
            }
        }

        return [null, true];
    }

    private function findEquivalentTicket(Event $event, array $payload): ?Ticket
    {
        return Ticket::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('name', $payload['name'])
            ->where('ticket_type', $payload['ticket_type'])
            ->where('type', $payload['type'])
            ->where('price', $payload['price'])
            ->where('quantity', $payload['quantity'])
            ->where(function ($query) use ($payload) {
                $description = $payload['description'] ?? null;
                $description === null
                    ? $query->whereNull('description')
                    : $query->where('description', $description);
            })
            ->first();
    }

    private function ownedEvent(mixed $user, int $id): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production')
            ->findOrFail($id);
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');

        abort_unless(
            $event->production && (int) $event->production->app_id === $this->context->id(),
            404,
            'Evento não encontrado neste contexto.'
        );
        abort_unless(
            $user && ($admin || (int) $event->production->user_id === (int) $user->id),
            403,
            'Você não pode gerenciar ingressos deste evento.'
        );

        return $event;
    }

    private function ownedTicket(mixed $user, int $id, bool $lock = false): Ticket
    {
        $query = Ticket::query()
            ->where('app_id', $this->context->id())
            ->with('event.production');

        if ($lock) {
            $query->lockForUpdate();
        }

        $ticket = $query->findOrFail($id);
        abort_unless($ticket->event, 404);
        $this->ownedEvent($user, (int) $ticket->event_id);

        return $ticket;
    }
}
