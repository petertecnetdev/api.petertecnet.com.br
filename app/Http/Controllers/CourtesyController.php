<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CourtesyController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function update(Request $request, int $ticketId)
    {
        $this->context->requireCapability('events');
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $ticket = DB::transaction(function () use ($ticketId, $data) {
            $ticket = $this->lockedOwnedTicket($ticketId);
            $issued = $ticket->passes()->count();
            if (isset($data['quantity'])) {
                abort_if((int) $data['quantity'] < $issued, 422, "A quantidade não pode ser menor que as {$issued} credenciais já emitidas.");
            }
            $ticket->update($data);
            return $ticket->fresh()->loadCount('passes');
        }, 3);

        return response()->json(['message' => 'Cortesia atualizada.', 'ticket' => $ticket]);
    }

    public function destroy(int $ticketId)
    {
        $this->context->requireCapability('events');
        DB::transaction(function () use ($ticketId) {
            $ticket = $this->lockedOwnedTicket($ticketId);
            abort_if($ticket->passes()->exists(), 409, 'Esta cortesia já possui credenciais emitidas e não pode ser excluída.');
            $ticket->delete();
        }, 3);

        return response()->json(['message' => 'Cortesia removida.']);
    }

    private function lockedOwnedTicket(int $ticketId): Ticket
    {
        $ticket = Ticket::query()
            ->where('app_id', $this->context->id())
            ->where('app_slug', $this->context->slug())
            ->with('event.production')
            ->lockForUpdate()
            ->findOrFail($ticketId);

        abort_unless(
            $ticket->event && (int) $ticket->event->app_id === $this->context->id()
                && $ticket->event->production
                && (int) $ticket->event->production->app_id === $this->context->id(),
            404,
            'Credencial não encontrada para esta aplicação.'
        );

        $user = Auth::user();
        abort_unless($user, 401, 'Faça login para gerenciar esta cortesia.');
        abort_unless($user->hasProfile('Administrador') || (int) $ticket->event->production->user_id === (int) $user->id, 403, 'Você não pode gerenciar esta credencial.');

        return $ticket;
    }
}
