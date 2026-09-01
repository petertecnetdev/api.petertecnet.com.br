<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class EventPassController extends Controller
{
    private const APP = 'cutinapp';

    public function claim(Request $request, int $ticketId)
    {
        $user = $this->requestUser($request);
        $application = $this->application();
        $alreadyIssued = false;

        $pass = DB::transaction(function () use ($ticketId, $user, $application, &$alreadyIssued) {
            $ticket = Ticket::query()
                ->where('app_id', $application->id)
                ->where('app_slug', self::APP)
                ->with('event.production')
                ->lockForUpdate()
                ->findOrFail($ticketId);

            abort_unless(
                $ticket->event
                    && (int) $ticket->event->app_id === (int) $application->id
                    && $ticket->event->app_slug === self::APP
                    && $ticket->event->production
                    && (int) $ticket->event->production->app_id === (int) $application->id
                    && ! $ticket->event->is_cancelled
                    && $ticket->event->is_published,
                422,
                'Este evento não está disponível para retirada de cortesias.'
            );
            abort_if((float) $ticket->price > 0, 422, 'Este ingresso não é uma cortesia gratuita.');
            abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, 'O prazo para retirada desta cortesia terminou.');

            $existing = EventPass::query()
                ->where('ticket_id', $ticket->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $alreadyIssued = true;
                return $existing->load(['ticket', 'event.production']);
            }

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

        $this->registerParticipation($application, $user->id, 'participant');

        return response()->json([
            'message' => $alreadyIssued
                ? 'Você já possui esta cortesia. Abrimos o ingresso já emitido.'
                : 'Cortesia retirada com sucesso.',
            'pass' => $pass,
            'already_issued' => $alreadyIssued,
        ], $alreadyIssued ? 200 : 201);
    }

    public function mine(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $passes = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($query) => $query
                ->where('app_id', $appId)
                ->where('app_slug', self::APP))
            ->with(['ticket', 'event.production'])
            ->latest()
            ->get();

        return response()->json(['passes' => $passes]);
    }

    public function show(Request $request, int $passId)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $pass = EventPass::query()
            ->whereHas('event', fn ($query) => $query
                ->where('app_id', $appId)
                ->where('app_slug', self::APP))
            ->with(['ticket', 'event.production', 'user:id,first_name,last_name,email,avatar'])
            ->findOrFail($passId);

        if ((int) $pass->user_id !== (int) $user->id) {
            $this->manageableEvent((int) $pass->event_id, $user);
        }

        return response()->json(['pass' => $pass]);
    }

    public function participants(Request $request, int $eventId)
    {
        $operator = $this->requestUser($request);
        $event = $this->manageableEvent($eventId, $operator);
        $passes = EventPass::query()
            ->where('event_id', $event->id)
            ->with(['ticket:id,app_id,name,event_id,app_slug', 'user:id,first_name,last_name,email,avatar'])
            ->orderBy('holder_name')
            ->get();

        return response()->json([
            'event' => $event->only(['id', 'title', 'start_date', 'end_date', 'slug', 'is_published']),
            'passes' => $passes,
            'stats' => [
                'issued' => $passes->count(),
                'checked_in' => $passes->whereNotNull('checked_in_at')->count(),
            ],
        ]);
    }

    public function validateToken(Request $request)
    {
        $operator = $this->requestUser($request);
        $data = $request->validate([
            'token' => 'required|string|max:180',
            'event_id' => 'required|integer|exists:events,id',
        ], [
            'token.required' => 'Leia ou informe o código do ingresso.',
            'event_id.required' => 'Selecione o evento da portaria antes de validar ingressos.',
            'event_id.exists' => 'O evento selecionado não foi encontrado.',
        ]);

        $selectedEvent = $this->manageableEvent((int) $data['event_id'], $operator);
        abort_if($selectedEvent->is_cancelled || ! $selectedEvent->is_published, 422, 'A portaria só pode validar um evento publicado e não cancelado.');

        $appId = $this->applicationId();

        $result = DB::transaction(function () use ($data, $operator, $selectedEvent, $appId) {
            $pass = EventPass::query()
                ->with(['ticket', 'event.production', 'user'])
                ->where('token', trim($data['token']))
                ->lockForUpdate()
                ->first();

            if (! $pass
                || (int) ($pass->ticket?->app_id ?? 0) !== $appId
                || (int) ($pass->event?->app_id ?? 0) !== $appId
                || (int) ($pass->event?->production?->app_id ?? 0) !== $appId
                || $pass->ticket?->app_slug !== self::APP
                || $pass->event?->app_slug !== self::APP) {
                return ['status' => 404, 'message' => 'QR Code inválido. Nenhum ingresso Cutinapp encontrado.', 'pass' => null];
            }

            if ((int) $pass->event_id !== (int) $selectedEvent->id) {
                return ['status' => 422, 'message' => 'Este ingresso pertence a outro evento.', 'pass' => $pass];
            }

            if ($pass->event->is_cancelled || ! $pass->event->is_published) {
                return ['status' => 422, 'message' => 'Este evento não está disponível para entrada.', 'pass' => $pass];
            }

            if (! $this->canOperateEvent($operator, $pass->event, $appId)) {
                return ['status' => 403, 'message' => 'Você não tem permissão para validar entradas deste evento.', 'pass' => $pass];
            }

            if ($pass->checked_in_at) {
                return ['status' => 409, 'message' => 'Este ingresso já foi utilizado anteriormente.', 'pass' => $pass];
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

        return response()->json(['message' => $result['message'], 'pass' => $result['pass']], $result['status']);
    }

    public function eventStats(Request $request, int $eventId)
    {
        $operator = $this->requestUser($request);
        $event = $this->manageableEvent($eventId, $operator);

        return response()->json([
            'event' => $event->only(['id', 'title', 'slug', 'is_published', 'is_cancelled']),
            'issued' => EventPass::query()->where('event_id', $eventId)->count(),
            'checked_in' => EventPass::query()->where('event_id', $eventId)->whereNotNull('checked_in_at')->count(),
        ]);
    }

    private function manageableEvent(int $eventId, User $operator): Event
    {
        $appId = $this->applicationId();
        $event = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->with('production')
            ->findOrFail($eventId);
        $production = $event->production;

        abort_unless($production && (int) $production->app_id === $appId, 404, 'Evento não encontrado na Cutinapp.');
        abort_unless($this->canOperateEvent($operator, $event, $appId), 403, 'Sem permissão para acessar a operação deste evento.');

        return $event;
    }

    private function canOperateEvent(User $operator, Event $event, int $appId): bool
    {
        $production = $event->production;

        if ($operator->hasProfile('Administrador')) {
            return true;
        }

        if ($production && (int) $production->user_id === (int) $operator->id) {
            return true;
        }

        if (! $operator->hasPermission('ticket_checkin') && ! $operator->hasPermission('event_checkin')) {
            return false;
        }

        $membership = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $operator->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return false;
        }

        $metadata = $membership->metadata ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true);
        } elseif (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        if (! is_array($metadata)) {
            return false;
        }

        $eventIds = array_map('intval', is_array($metadata['event_ids'] ?? null) ? $metadata['event_ids'] : []);
        $productionIds = array_map('intval', is_array($metadata['production_ids'] ?? null) ? $metadata['production_ids'] : []);

        return in_array((int) $event->id, $eventIds, true)
            || ($production && in_array((int) $production->id, $productionIds, true));
    }

    private function requestUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());
        abort_if($token === '', 401, 'Sessão inválida ou expirada. Faça login novamente.');

        try {
            $user = JWTAuth::setToken($token)->authenticate();
        } catch (\Throwable) {
            $user = null;
        }

        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }

    private function application(): Application
    {
        $application = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless($application, 503, 'A Cutinapp não está registrada corretamente na API. Execute as migrations e tente novamente.');
        return $application;
    }

    private function applicationId(): int
    {
        return (int) $this->application()->id;
    }

    private function registerParticipation(Application $application, int $userId, string $role): void
    {
        $existing = DB::table('application_user')
            ->where('application_id', $application->id)
            ->where('user_id', $userId)
            ->first();

        $priority = ['participant' => 10, 'staff' => 20, 'promoter' => 30, 'producer' => 40, 'admin' => 50];
        $existingRole = (string) ($existing->role ?? '');
        $effectiveRole = ($priority[$existingRole] ?? 0) > ($priority[$role] ?? 0) ? $existingRole : $role;

        if ($existing) {
            DB::table('application_user')
                ->where('application_id', $application->id)
                ->where('user_id', $userId)
                ->update([
                    'role' => $effectiveRole,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('application_user')->insert([
            'application_id' => $application->id,
            'user_id' => $userId,
            'role' => $effectiveRole,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
