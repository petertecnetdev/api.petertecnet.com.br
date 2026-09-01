<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CutinappCourtesyController extends Controller
{
    private const APP = 'cutinapp';

    public function update(Request $request, int $ticketId)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'limit_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string|max:5000',
        ], [
            'required' => 'Preencha :attribute.',
            'integer' => ':attribute precisa ser um número inteiro.',
            'date' => 'Informe uma data válida em :attribute.',
            'min' => ':attribute está abaixo do mínimo permitido.',
            'max' => ':attribute ultrapassou o limite permitido.',
        ], [
            'name' => 'o nome da cortesia',
            'quantity' => 'a quantidade',
            'limit_date' => 'a data limite',
            'description' => 'a descrição',
        ]);

        $ticket = DB::transaction(function () use ($ticketId, $data) {
            $ticket = $this->lockedOwnedTicket($ticketId);
            $issued = $ticket->passes()->count();

            if (isset($data['quantity'])) {
                abort_if(
                    (int) $data['quantity'] < $issued,
                    422,
                    "A quantidade não pode ser menor que os {$issued} ingressos já emitidos."
                );
            }

            $ticket->update($data);

            return $ticket->fresh()->loadCount('passes');
        }, 3);

        return response()->json([
            'message' => 'Cortesia atualizada.',
            'ticket' => $ticket,
        ]);
    }

    public function destroy(int $ticketId)
    {
        DB::transaction(function () use ($ticketId) {
            $ticket = $this->lockedOwnedTicket($ticketId);

            abort_if(
                $ticket->passes()->exists(),
                409,
                'Esta cortesia já possui ingressos emitidos e não pode ser excluída.'
            );

            $ticket->delete();
        }, 3);

        return response()->json(['message' => 'Cortesia removida.']);
    }

    private function lockedOwnedTicket(int $ticketId): Ticket
    {
        $appId = $this->applicationId();
        $ticket = Ticket::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->with('event.production')
            ->lockForUpdate()
            ->findOrFail($ticketId);

        abort_unless(
            $ticket->event
                && (int) $ticket->event->app_id === $appId
                && $ticket->event->app_slug === self::APP
                && $ticket->event->production
                && (int) $ticket->event->production->app_id === $appId
                && $ticket->event->production->app_slug === self::APP,
            404,
            'Ingresso não encontrado na Cutinapp.'
        );

        $user = Auth::user();
        abort_unless($user, 401, 'Faça login para gerenciar esta cortesia.');
        abort_unless(
            $user->hasProfile('Administrador')
                || (int) $ticket->event->production->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar este ingresso.'
        );

        return $ticket;
    }

    private function applicationId(): int
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless(
            $application,
            503,
            'A Cutinapp não está registrada corretamente na API. Execute as migrations e tente novamente.'
        );

        return (int) $application->id;
    }
}
