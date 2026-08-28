<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketController extends Controller
{
    public function list(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        return response()->json(
            Ticket::query()->with('event')->latest()->paginate($perPage)
        );
    }

    public function show($id)
    {
        return response()->json([
            'ticket' => Ticket::query()->with('event.production')->findOrFail($id),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $event = Event::query()->with('production')->findOrFail($data['event_id']);

        if (! $this->canManageEvent($event, 'ticket_create')) {
            return response()->json(['error' => 'Você não tem permissão para criar ingressos neste evento.'], 403);
        }

        $ticket = Ticket::create($data);

        return response()->json([
            'message' => 'Ingresso criado com sucesso.',
            'ticket' => $ticket->load('event'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $ticket = Ticket::query()->with('event.production')->findOrFail($id);

        if (! $this->canManageEvent($ticket->event, 'ticket_edit')) {
            return response()->json(['error' => 'Você não tem permissão para atualizar este ingresso.'], 403);
        }

        $data = $request->validate($this->rules(false));

        if (isset($data['event_id']) && (int) $data['event_id'] !== (int) $ticket->event_id) {
            $newEvent = Event::query()->with('production')->findOrFail($data['event_id']);

            if (! $this->canManageEvent($newEvent, 'ticket_edit')) {
                return response()->json(['error' => 'Você não tem permissão para mover o ingresso para este evento.'], 403);
            }
        }

        $ticket->update($data);

        return response()->json([
            'message' => 'Ingresso atualizado com sucesso.',
            'ticket' => $ticket->fresh()->load('event'),
        ]);
    }

    public function destroy($id)
    {
        $ticket = Ticket::query()->with('event.production')->findOrFail($id);

        if (! $this->canManageEvent($ticket->event, 'ticket_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir este ingresso.'], 403);
        }

        $ticket->delete();

        return response()->json(['message' => 'Ingresso excluído com sucesso.']);
    }

    public function listByEvent(Request $request, $eventId)
    {
        Event::query()->findOrFail($eventId);
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json(
            Ticket::query()
                ->where('event_id', $eventId)
                ->orderBy('price')
                ->paginate($perPage)
        );
    }

    public function listByUser(Request $request)
    {
        $user = Auth::user();
        $eventIds = $user->events()->pluck('events.id');
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json(
            Ticket::query()
                ->whereIn('event_id', $eventIds)
                ->with('event')
                ->latest()
                ->paginate($perPage)
        );
    }

    public function listByProduction(Request $request, $productionId)
    {
        $production = Production::query()->findOrFail($productionId);
        $user = Auth::user();

        if ((int) $production->user_id !== (int) $user->id && ! $user->hasProfile('Administrador')) {
            return response()->json(['error' => 'Você não tem permissão para acessar esta produção.'], 403);
        }

        $eventIds = $production->events()->pluck('id');
        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json(
            Ticket::query()
                ->whereIn('event_id', $eventIds)
                ->with('event')
                ->latest()
                ->paginate($perPage)
        );
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : 'sometimes|';

        return [
            'event_id' => $required . 'integer|exists:events,id',
            'name' => $required . 'string|max:255',
            'ticket_type' => $required . 'string|max:255',
            'type' => 'sometimes|nullable|string|max:255',
            'price' => $required . 'numeric|min:0',
            'quantity' => $required . 'integer|min:0',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ];
    }

    private function canManageEvent(Event $event, string $permission): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->hasProfile('Administrador')
            || ((int) optional($event->production)->user_id === (int) $user->id)
            || $user->hasPermission($permission);
    }
}
