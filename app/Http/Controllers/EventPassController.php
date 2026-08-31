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
    private const APP = 'cutinapp';

    public function claim(Request $request, int $ticketId)
    {
        $user = Auth::user();

        $pass = DB::transaction(function () use ($ticketId, $user) {
            $ticket = Ticket::query()
                ->where('app_slug', self::APP)
                ->with('event.production')
                ->lockForUpdate()
                ->findOrFail($ticketId);

            abort_unless($ticket->event && $ticket->event->app_slug === self::APP && ! $ticket->event->is_cancelled && $ticket->event->is_published, 422, 'Este evento não está disponível para retirada de cortesias.');
            abort_if((float) $ticket->price > 0, 422, 'Este ingresso não é uma cortesia gratuita.');
            abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, 'O prazo para retirada desta cortesia terminou.');

            $existing = EventPass::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->first();
            if ($existing) return $existing->load(['ticket', 'event.production']);

            $issued = EventPass::query()->where('ticket_id', $ticket->id)->count();
            abort_if((int) $ticket->quantity <= 0 || $issued >= (int) $ticket->quantity, 422, 'As cortesias deste lote estão esgotadas.');

            return EventPass::create([
                'ticket_id' => $ticket->id,
                'event_id' => $ticket->event_id,
                'user_id' => $user->id,
                'holder_name' => trim((string) ($user->first_name ?? $user->name ?? 'Participante')),
                'holder_email' => strtolower(trim((string) $user->email)),
                'token' => 'CUT-' . Str::upper(Str::random(16)) . '-' . Str::uuid(),
                'status' => 'issued',
            ])->load(['ticket', 'event.production']);
        });

        return response()->json(['message' => 'Cortesia retirada com sucesso.', 'pass' => $pass], 201);
    }

    public function mine()
    {
        $passes = EventPass::query()
            ->where('user_id', Auth::id())
            ->whereHas('event', fn ($query) => $query->where('app_slug', self::APP))
            ->with(['ticket', 'event.production'])
            ->latest()
            ->get();

        return response()->json(['passes' => $passes]);
    }

    public function participants(int $eventId)
    {
        $event = $this->manageableEvent($eventId);
        $passes = EventPass::query()
            ->where('event_id', $event->id)
            ->with(['ticket:id,name,event_id,app_slug', 'user:id,first_name,last_name,email,avatar'])
            ->orderBy('holder_name')
            ->get();

        return response()->json([
            'event' => $event->only(['id', 'title', 'start_date', 'end_date']),
            'passes' => $passes,
            'stats' => [
                'issued' => $passes->count(),
                'checked_in' => $passes->whereNotNull('checked_in_at')->count(),
            ],
        ]);
    }

    public function validateToken(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:180']);
        $operator = Auth::user();

        $result = DB::transaction(function () use ($data, $operator) {
            $pass = EventPass::query()
                ->with(['ticket', 'event.production', 'user'])
                ->where('token', trim($data['token']))
                ->lockForUpdate()
                ->first();

            if (! $pass || $pass->ticket?->app_slug !== self::APP || $pass->event?->app_slug !== self::APP) {
                return ['status' => 404, 'message' => 'QR Code inválido. Nenhum ingresso Cutinapp encontrado.', 'pass' => null];
            }

            if ($pass->event->is_cancelled || ! $pass->event->is_published) {
                return ['status' => 422, 'message' => 'Este evento não está disponível para entrada.', 'pass' => $pass];
            }

            $production = $pass->event->production;
            $canCheckIn = $operator->hasProfile('Administrador')
                || ($production && (int) $production->user_id === (int) $operator->id)
                || $operator->hasPermission('ticket_checkin')
                || $operator->hasPermission('event_checkin');

            if (! $canCheckIn) return ['status' => 403, 'message' => 'Você não tem permissão para validar entradas deste evento.', 'pass' => $pass];

            if ($pass->checked_in_at) {
                return ['status' => 409, 'message' => 'Este ingresso já foi utilizado anteriormente.', 'pass' => $pass];
            }

            $pass->forceFill(['status' => 'checked_in', 'checked_in_at' => now(), 'checked_in_by' => $operator->id])->save();
            return ['status' => 200, 'message' => 'Entrada validada com sucesso.', 'pass' => $pass->fresh()->load(['ticket', 'event.production', 'user'])];
        });

        return response()->json(['message' => $result['message'], 'pass' => $result['pass']], $result['status']);
    }

    public function eventStats(int $eventId)
    {
        $event = $this->manageableEvent($eventId);
        return response()->json([
            'event' => $event->only(['id', 'title']),
            'issued' => EventPass::query()->where('event_id', $eventId)->count(),
            'checked_in' => EventPass::query()->where('event_id', $eventId)->whereNotNull('checked_in_at')->count(),
        ]);
    }

    private function manageableEvent(int $eventId): Event
    {
        $operator = Auth::user();
        $event = Event::query()->where('app_slug', self::APP)->with('production')->findOrFail($eventId);
        $production = $event->production;
        $allowed = $operator->hasProfile('Administrador')
            || ($production && (int) $production->user_id === (int) $operator->id)
            || $operator->hasPermission('ticket_checkin')
            || $operator->hasPermission('event_checkin');
        abort_unless($allowed, 403, 'Sem permissão para acessar a operação deste evento.');
        return $event;
    }
}
