<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventPassController extends Controller
{
    public function claim(Request $request, int $ticketId)
    {
        $user = Auth::user();

        $pass = DB::transaction(function () use ($ticketId, $user) {
            $ticket = Ticket::query()
                ->with('event.production')
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if (! $ticket->event || $ticket->event->is_cancelled) {
                abort(422, 'Este evento não está disponível para retirada de cortesias.');
            }

            if ((float) $ticket->price > 0) {
                abort(422, 'Este ingresso não é uma cortesia gratuita.');
            }

            if ($ticket->limit_date && now()->greaterThan($ticket->limit_date)) {
                abort(422, 'O prazo para retirada desta cortesia terminou.');
            }

            $existing = EventPass::query()
                ->where('ticket_id', $ticket->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return $existing->load(['ticket', 'event.production']);
            }

            $issued = EventPass::query()->where('ticket_id', $ticket->id)->count();
            if ((int) $ticket->quantity <= 0 || $issued >= (int) $ticket->quantity) {
                abort(422, 'As cortesias deste lote estão esgotadas.');
            }

            return EventPass::create([
                'ticket_id' => $ticket->id,
                'event_id' => $ticket->event_id,
                'user_id' => $user->id,
                'holder_name' => trim((string) ($user->first_name ?? $user->name ?? 'Participante')),
                'holder_email' => strtolower(trim((string) $user->email)),
                'token' => 'CUT-' . Str::upper(Str::random(12)) . '-' . Str::uuid(),
                'status' => 'issued',
            ])->load(['ticket', 'event.production']);
        });

        return response()->json([
            'message' => 'Cortesia retirada com sucesso.',
            'pass' => $pass,
        ], 201);
    }

    public function mine()
    {
        $passes = EventPass::query()
            ->where('user_id', Auth::id())
            ->with(['ticket', 'event.production'])
            ->latest()
            ->get();

        return response()->json(['passes' => $passes]);
    }

    public function validateToken(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:160',
        ]);

        $operator = Auth::user();

        $result = DB::transaction(function () use ($data, $operator) {
            $pass = EventPass::query()
                ->with(['ticket', 'event.production', 'user'])
                ->where('token', trim($data['token']))
                ->lockForUpdate()
                ->first();

            if (! $pass) {
                return ['status' => 404, 'message' => 'QR Code inválido. Nenhuma cortesia encontrada.', 'pass' => null];
            }

            $production = optional($pass->event)->production;
            $canCheckIn = $operator->hasProfile('Administrador')
                || ($production && (int) $production->user_id === (int) $operator->id)
                || $operator->hasPermission('ticket_checkin')
                || $operator->hasPermission('event_checkin');

            if (! $canCheckIn) {
                return ['status' => 403, 'message' => 'Você não tem permissão para validar entradas deste evento.', 'pass' => $pass];
            }

            if ($pass->checked_in_at) {
                return [
                    'status' => 409,
                    'message' => 'Esta cortesia já foi utilizada anteriormente.',
                    'pass' => $pass,
                ];
            }

            $pass->forceFill([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by' => $operator->id,
            ])->save();

            return [
                'status' => 200,
                'message' => 'Entrada validada com sucesso.',
                'pass' => $pass->fresh()->load(['ticket', 'event.production', 'user']),
            ];
        });

        return response()->json([
            'message' => $result['message'],
            'pass' => $result['pass'],
        ], $result['status']);
    }

    public function eventStats(int $eventId)
    {
        $operator = Auth::user();
        $event = Event::query()->with('production')->findOrFail($eventId);
        $production = $event->production;

        $allowed = $operator->hasProfile('Administrador')
            || ($production && (int) $production->user_id === (int) $operator->id)
            || $operator->hasPermission('ticket_checkin')
            || $operator->hasPermission('event_checkin');

        abort_unless($allowed, 403, 'Sem permissão para visualizar esta portaria.');

        return response()->json([
            'issued' => EventPass::query()->where('event_id', $eventId)->count(),
            'checked_in' => EventPass::query()->where('event_id', $eventId)->whereNotNull('checked_in_at')->count(),
        ]);
    }
}
