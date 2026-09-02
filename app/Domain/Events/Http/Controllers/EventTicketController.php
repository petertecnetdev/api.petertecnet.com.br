<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EventTicketController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request, int $eventId)
    {
        $event = $this->ownedEvent($request, $eventId);
        return response()->json([
            'event' => $event->only(['id','title','slug','is_published','is_cancelled']),
            'tickets' => Ticket::query()->where('app_id', $this->context->id())->where('event_id', $event->id)->withCount('passes')->orderBy('created_at')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id', 'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000', 'price' => 'required|numeric|min:0|max:999999.99',
            'ticket_type' => 'nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'nullable|date', 'description' => 'nullable|string|max:5000',
        ]);
        $event = $this->ownedEvent($request, (int) $data['event_id']);
        abort_if($event->is_cancelled, 422, 'Não é possível criar ingressos para um evento cancelado.');
        $price = round((float) $data['price'], 2); $paid = $price > 0;
        $ticket = Ticket::create([
            'app_id' => $this->context->id(), 'app_slug' => $this->context->slug(), 'event_id' => $event->id,
            'name' => trim($data['name']), 'ticket_type' => $paid ? ($data['ticket_type'] ?? 'standard') : 'courtesy',
            'type' => $paid ? 'paid' : 'courtesy', 'price' => $price, 'quantity' => (int) $data['quantity'],
            'limit_date' => $data['limit_date'] ?? null, 'description' => $data['description'] ?? null,
        ]);
        return response()->json(['message' => $paid ? 'Ingresso criado com sucesso.' : 'Cortesia criada com sucesso.', 'ticket' => $ticket->loadCount('passes')], 201);
    }

    public function update(Request $request, int $ticketId)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255', 'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99', 'ticket_type' => 'sometimes|nullable|in:courtesy,standard,vip,premium,student,half,full',
            'limit_date' => 'sometimes|nullable|date', 'description' => 'sometimes|nullable|string|max:5000',
        ]);
        $ticket = DB::transaction(function () use ($request, $ticketId, $data) {
            $ticket = $this->ownedTicket($request, $ticketId, true); $issued = $ticket->passes()->count();
            if (isset($data['quantity'])) abort_if((int) $data['quantity'] < $issued, 422, "A quantidade não pode ser menor que os {$issued} ingressos já emitidos.");
            if (array_key_exists('price', $data)) { $price = round((float) $data['price'], 2); $data['type'] = $price > 0 ? 'paid' : 'courtesy'; if ($price <= 0) $data['ticket_type'] = 'courtesy'; }
            $ticket->update($data); return $ticket->fresh()->loadCount('passes');
        }, 3);
        return response()->json(['message' => 'Ingresso atualizado.', 'ticket' => $ticket]);
    }

    public function destroy(Request $request, int $ticketId)
    {
        DB::transaction(function () use ($request, $ticketId) {
            $ticket = $this->ownedTicket($request, $ticketId, true);
            abort_if($ticket->passes()->exists(), 409, 'Este ingresso já possui emissões e não pode ser excluído.');
            $ticket->delete();
        }, 3);
        return response()->json(['message' => 'Ingresso removido.']);
    }

    private function ownedEvent(Request $request, int $id): Event
    {
        $event = Event::query()->where('app_id', $this->context->id())->with('production')->findOrFail($id);
        $user = $request->user(); $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($event->production && (int) $event->production->app_id === $this->context->id(), 404, 'Evento não encontrado neste contexto.');
        abort_unless($user && ($admin || (int) $event->production->user_id === (int) $user->id), 403, 'Você não pode gerenciar ingressos deste evento.');
        return $event;
    }

    private function ownedTicket(Request $request, int $id, bool $lock = false): Ticket
    {
        $query = Ticket::query()->where('app_id', $this->context->id())->with('event.production'); if ($lock) $query->lockForUpdate();
        $ticket = $query->findOrFail($id); abort_unless($ticket->event, 404); $this->ownedEvent($request, (int) $ticket->event_id); return $ticket;
    }
}
