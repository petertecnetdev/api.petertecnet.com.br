<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EventTicketController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request, int $eventId)
    {
        $event = $this->ownedEvent($request, $eventId);

        return response()->json([
            'event' => $event->only(['id', 'title', 'slug', 'is_published', 'is_cancelled']),
            'tickets' => Ticket::query()
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->withCount('passes')
                ->orderBy('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'nullable|required_without:event_ids|integer|exists:events,id',
            'event_ids' => 'nullable|required_without:event_id|array|min:1|max:100',
            'event_ids.*' => 'required|integer|distinct|exists:events,id',
            'source_ticket_id' => 'nullable|integer|exists:tickets,id',
            'name' => 'nullable|required_without:source_ticket_id|string|max:255',
            'quantity' => 'nullable|required_without:source_ticket_id|integer|min:1|max:100000',
            'price' => 'nullable|required_without:source_ticket_id|numeric|min:0|max:999999.99',
            'ticket_type' => 'nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'nullable|date',
            'description' => 'nullable|string|max:5000',
        ]);

        $eventIds = collect($data['event_ids'] ?? [(int) $data['event_id']])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $sourceTicket = isset($data['source_ticket_id'])
            ? $this->ownedTicket($request, (int) $data['source_ticket_id'])
            : null;

        $result = DB::transaction(function () use ($request, $data, $eventIds, $sourceTicket) {
            $created = collect();
            $linked = collect();
            $skipped = collect();
            $adjustedLimitDates = collect();

            foreach ($eventIds as $eventId) {
                $event = $this->ownedEvent($request, $eventId);
                abort_if($event->is_cancelled, 422, "Não é possível criar ingressos para o evento cancelado {$event->title}.");

                if ($sourceTicket && (int) $sourceTicket->event_id === (int) $event->id) {
                    $linked->push($sourceTicket->fresh()->loadCount('passes'));
                    continue;
                }

                [$payload, $limitAdjusted] = $this->payloadForEvent($data, $sourceTicket, $event);
                $equivalent = $this->findEquivalentTicket($event, $payload);

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

        /** @var Collection $tickets */
        $tickets = collect()
            ->concat($result['created'])
            ->concat($result['linked'])
            ->concat($result['skipped'])
            ->unique('id')
            ->values();

        $firstTicket = $tickets->first();
        $isBulk = $eventIds->count() > 1 || $sourceTicket !== null;

        return response()->json([
            'message' => $isBulk
                ? sprintf(
                    'Ingresso aplicado a %d evento(s): %d criado(s), %d já existente(s).',
                    $tickets->count(),
                    $result['created']->count(),
                    $result['linked']->count() + $result['skipped']->count()
                )
                : ((float) optional($firstTicket)->price > 0 ? 'Ingresso criado com sucesso.' : 'Cortesia criada com sucesso.'),
            'ticket' => $firstTicket,
            'tickets' => $tickets,
            'created_count' => $result['created']->count(),
            'existing_count' => $result['linked']->count() + $result['skipped']->count(),
            'adjusted_limit_dates' => $result['adjustedLimitDates'],
        ], $result['created']->isNotEmpty() ? 201 : 200);
    }

    public function update(Request $request, int $ticketId)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'ticket_type' => 'sometimes|nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $ticket = DB::transaction(function () use ($request, $ticketId, $data) {
            $ticket = $this->ownedTicket($request, $ticketId, true);
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

        return response()->json(['message' => 'Ingresso atualizado.', 'ticket' => $ticket]);
    }

    public function destroy(Request $request, int $ticketId)
    {
        DB::transaction(function () use ($request, $ticketId) {
            $ticket = $this->ownedTicket($request, $ticketId, true);
            if ($ticket->passes()->exists()) {
                abort(409, 'Este ingresso já possui emissões e não pode ser excluído.');
            }
            $ticket->delete();
        }, 3);

        return response()->json(['message' => 'Ingresso removido.']);
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

    private function ownedEvent(Request $request, int $id): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production')
            ->findOrFail($id);
        $user = $request->user();
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

    private function ownedTicket(Request $request, int $id, bool $lock = false): Ticket
    {
        $query = Ticket::query()
            ->where('app_id', $this->context->id())
            ->with('event.production');

        if ($lock) {
            $query->lockForUpdate();
        }

        $ticket = $query->findOrFail($id);
        abort_unless($ticket->event, 404);
        $this->ownedEvent($request, (int) $ticket->event_id);

        return $ticket;
    }
}
