<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventPassService
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function claim(int $ticketId, User $user): array
    {
        $appId = $this->context->id();
        $alreadyIssued = false;

        $pass = DB::transaction(function () use ($ticketId, $user, $appId, &$alreadyIssued) {
            $ticketReference = Ticket::query()
                ->where('app_id', $appId)
                ->select(['id', 'event_id'])
                ->findOrFail($ticketId);

            $event = Event::query()
                ->where('app_id', $appId)
                ->with('production')
                ->lockForUpdate()
                ->findOrFail($ticketReference->event_id);

            $ticket = Ticket::query()
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            abort_unless(
                $event->production
                    && (int) $event->production->app_id === $appId
                    && ! $event->is_cancelled
                    && $event->is_published
                    && ! $event->is_private,
                422,
                'Este evento não está disponível para retirada pública de cortesias.'
            );

            abort_if(
                $event->end_date && now()->greaterThanOrEqualTo($event->end_date),
                422,
                'Este evento já foi encerrado. Não é mais possível emitir ingressos.'
            );
            abort_if((float) $ticket->price > 0, 422, 'Este ingresso não é uma cortesia gratuita.');
            abort_if(
                $ticket->limit_date && now()->greaterThan($ticket->limit_date),
                422,
                'O prazo para retirada desta cortesia terminou.'
            );

            $existing = EventPass::query()
                ->where('ticket_id', $ticket->id)
                ->where('user_id', $user->id)
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->first();

            if ($existing) {
                $alreadyIssued = true;

                return $existing->load(['ticket', 'event.production']);
            }

            $issuedForTicket = EventPass::query()
                ->where('ticket_id', $ticket->id)
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->count();

            abort_if(
                (int) $ticket->quantity <= 0 || $issuedForTicket >= (int) $ticket->quantity,
                422,
                'As cortesias deste lote estão esgotadas.'
            );

            $eventCapacity = (int) ($event->max_attendees ?? 0);
            if ($eventCapacity > 0) {
                $issuedForEvent = EventPass::query()
                    ->where('event_id', $event->id)
                    ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                    ->count();

                abort_if(
                    $issuedForEvent >= $eventCapacity,
                    422,
                    'A capacidade máxima deste evento foi atingida.'
                );
            }

            return EventPass::create([
                'ticket_id' => $ticket->id,
                'event_id' => $event->id,
                'user_id' => $user->id,
                'holder_name' => trim((string) ($user->first_name ?? $user->name ?? 'Participante')),
                'holder_email' => strtolower(trim((string) $user->email)),
                'token' => 'PASS-'.Str::upper(Str::random(16)).'-'.Str::uuid(),
                'status' => 'issued',
            ])->load(['ticket', 'event.production']);
        });

        $this->registerParticipation($appId, (int) $user->id, 'participant');

        if (! $alreadyIssued) {
            AppNotification::firstOrCreate(
                [
                    'app_id' => $appId,
                    'user_id' => (int) $user->id,
                    'type' => 'ticket_issued',
                    'reference_type' => 'event_pass',
                    'reference_id' => (int) $pass->id,
                ],
                [
                    'title' => 'Ingresso emitido',
                    'message' => 'Seu ingresso para '.($pass->event?->title ?: 'o evento').' está disponível.',
                    'reference_url' => '/passes/'.$pass->id,
                    'data' => [
                        'event_id' => (int) $pass->event_id,
                        'ticket_id' => (int) $pass->ticket_id,
                        'pass_id' => (int) $pass->id,
                    ],
                ]
            );
        }

        return [
            'message' => $alreadyIssued
                ? 'Você já possui esta cortesia. Abrimos o ingresso já emitido.'
                : 'Cortesia retirada com sucesso.',
            'pass' => $pass,
            'already_issued' => $alreadyIssued,
            'status' => $alreadyIssued ? 200 : 201,
        ];
    }

    public function mine(User $user): array
    {
        $appId = $this->context->id();
        $passes = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
            ->with(['ticket', 'event.production'])
            ->latest()
            ->get();

        return ['passes' => $passes];
    }

    public function show(User $user, int $passId): array
    {
        $appId = $this->context->id();
        $pass = EventPass::query()
            ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
            ->with(['ticket', 'event.production', 'user:id,first_name,last_name,email,avatar'])
            ->findOrFail($passId);

        if ((int) $pass->user_id !== (int) $user->id) {
            $this->manageableEvent((int) $pass->event_id, $user);
            $pass->makeHidden('token');
        }

        return ['pass' => $pass];
    }

    public function participants(User $operator, int $eventId): array
    {
        $event = $this->manageableEvent($eventId, $operator);
        $passes = EventPass::query()
            ->where('event_id', $event->id)
            ->with([
                'ticket:id,app_id,name,event_id,app_slug',
                'user:id,first_name,last_name,email,avatar',
            ])
            ->orderBy('holder_name')
            ->get();

        $passes->each->makeHidden('token');
        $valid = $passes->whereNotIn('status', self::INVALID_PASS_STATUSES);

        return [
            'event' => $event->only(['id', 'title', 'start_date', 'end_date', 'slug', 'is_published']),
            'passes' => $passes,
            'stats' => [
                'issued' => $valid->count(),
                'checked_in' => $valid->whereNotNull('checked_in_at')->count(),
            ],
        ];
    }

    public function validateToken(User $operator, string $token, int $eventId): array
    {
        $selectedEvent = $this->manageableEvent($eventId, $operator);
        abort_if(
            $selectedEvent->is_cancelled || ! $selectedEvent->is_published,
            422,
            'A portaria só pode validar um evento publicado e não cancelado.'
        );
        $appId = $this->context->id();

        $result = DB::transaction(function () use ($token, $operator, $selectedEvent, $appId) {
            $pass = EventPass::query()
                ->with(['ticket', 'event.production', 'user'])
                ->where('token', trim($token))
                ->lockForUpdate()
                ->first();

            if (
                ! $pass
                || (int) ($pass->ticket?->app_id ?? 0) !== $appId
                || (int) ($pass->event?->app_id ?? 0) !== $appId
                || (int) ($pass->event?->production?->app_id ?? 0) !== $appId
            ) {
                return [
                    'status' => 404,
                    'message' => 'QR Code inválido. Nenhum ingresso deste contexto foi encontrado.',
                    'pass' => null,
                ];
            }

            if ((int) $pass->event_id !== (int) $selectedEvent->id) {
                return ['status' => 422, 'message' => 'Este ingresso pertence a outro evento.', 'pass' => null];
            }

            if (in_array((string) $pass->status, self::INVALID_PASS_STATUSES, true)) {
                $message = match ((string) $pass->status) {
                    'refunded' => 'Este ingresso foi reembolsado e não pode ser utilizado.',
                    'charged_back' => 'Este ingresso foi invalidado por contestação do pagamento.',
                    'cancelled' => 'Este ingresso foi cancelado e não pode ser utilizado.',
                    default => 'Este ingresso não está válido para entrada.',
                };

                return ['status' => 422, 'message' => $message, 'pass' => $pass];
            }

            if ($pass->event->is_cancelled || ! $pass->event->is_published) {
                return ['status' => 422, 'message' => 'Este evento não está disponível para entrada.', 'pass' => $pass];
            }

            if (! $this->canOperateEvent($operator, $pass->event, $appId)) {
                return [
                    'status' => 403,
                    'message' => 'Você não tem permissão para validar entradas deste evento.',
                    'pass' => null,
                ];
            }

            $now = now();
            if ($pass->event->start_date && $now->lt($pass->event->start_date)) {
                return ['status' => 422, 'message' => 'Este ingresso ainda não pode ser utilizado.', 'pass' => $pass];
            }
            if ($pass->event->end_date && $now->gt($pass->event->end_date)) {
                return ['status' => 422, 'message' => 'Este ingresso não pode mais ser utilizado.', 'pass' => $pass];
            }
            if ($pass->checked_in_at) {
                return ['status' => 409, 'message' => 'Este ingresso já foi utilizado anteriormente.', 'pass' => $pass];
            }

            $pass->forceFill([
                'status' => 'checked_in',
                'checked_in_at' => $now,
                'checked_in_by' => $operator->id,
            ])->save();

            return [
                'status' => 200,
                'message' => 'Entrada validada com sucesso.',
                'pass' => $pass->fresh()->load(['ticket', 'event.production', 'user']),
            ];
        });

        if ($result['pass'] instanceof EventPass) {
            $result['pass']->makeHidden('token');
        }

        return $result;
    }

    public function eventStats(User $operator, int $eventId): array
    {
        $event = $this->manageableEvent($eventId, $operator);
        $valid = EventPass::query()
            ->where('event_id', $eventId)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES);

        return [
            'event' => $event->only(['id', 'title', 'slug', 'is_published', 'is_cancelled', 'start_date', 'end_date']),
            'issued' => (clone $valid)->count(),
            'checked_in' => (clone $valid)->whereNotNull('checked_in_at')->count(),
        ];
    }

    private function manageableEvent(int $eventId, User $operator): Event
    {
        $appId = $this->context->id();
        $event = Event::query()
            ->where('app_id', $appId)
            ->with('production')
            ->findOrFail($eventId);

        abort_unless(
            $event->production && (int) $event->production->app_id === $appId,
            404,
            'Evento não encontrado neste contexto.'
        );
        abort_unless(
            $this->canOperateEvent($operator, $event, $appId),
            403,
            'Sem permissão para acessar a operação deste evento.'
        );

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

    private function registerParticipation(int $appId, int $userId, string $role): void
    {
        $existing = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $userId)
            ->first();

        $priority = [
            'participant' => 10,
            'staff' => 20,
            'promoter' => 30,
            'producer' => 40,
            'admin' => 50,
        ];
        $existingRole = (string) ($existing->role ?? '');
        $effective = ($priority[$existingRole] ?? 0) > ($priority[$role] ?? 0)
            ? $existingRole
            : $role;

        if ($existing) {
            DB::table('application_user')
                ->where('application_id', $appId)
                ->where('user_id', $userId)
                ->update([
                    'role' => $effective,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('application_user')->insert([
            'application_id' => $appId,
            'user_id' => $userId,
            'role' => $effective,
            'status' => 'active',
            'metadata' => json_encode([], JSON_UNESCAPED_UNICODE),
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
