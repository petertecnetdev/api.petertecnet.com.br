<?php

namespace App\Services;

use App\Domain\Platform\Services\ApplicationAdminService;
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

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ApplicationAdminService $applicationAdmin,
    )
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
            abort_if((bool) $ticket->curatorial_information_only, 403,
                'Ingresso curatorial disponível apenas para consulta.');
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
            ->with([
                'ticket',
                'event.production',
                'orderItem.order.payments' => fn ($query) => $query->latest('id'),
            ])
            ->latest()
            ->get();

        $passes->each(function (EventPass $pass): void {
            $orderItem = $pass->orderItem;
            $order = $orderItem?->order;
            $event = $pass->event;
            $ticket = $pass->ticket;

            // The wallet index never needs the credential itself. The holder can
            // retrieve the QR only from the authenticated pass detail endpoint.
            $pass->makeHidden('token');
            $pass->setAttribute('secure_qr', [
                'available' => true,
                'detail_url' => '/passes/'.$pass->id,
            ]);
            $pass->setAttribute('is_complimentary', (float) ($ticket?->price ?? 0) <= 0);
            $pass->setAttribute('purchase', $order ? [
                'id' => (int) $order->id,
                'public_id' => (string) $order->public_id,
                'status' => (string) $order->status,
                'payment_method' => $order->payment_method,
                'total' => $order->total,
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'platform_fee' => $order->platform_fee,
                'processor_fee' => $order->processor_fee,
                'paid_at' => optional($order->paid_at)->toIso8601String(),
                'purchased_at' => optional($order->created_at)->toIso8601String(),
                'line_unit_price' => $orderItem?->unit_price,
                'line_subtotal' => $orderItem?->subtotal,
                'latest_payment_status' => optional($order->payments->first())->status,
            ] : null);
            $pass->setAttribute('wallet_state', [
                'event_status' => $event?->temporal_status,
                'event_cancelled' => (bool) ($event?->is_cancelled),
                'checked_in' => $pass->checked_in_at !== null || (string) $pass->status === 'checked_in',
                'transferable' => ! in_array((string) $pass->status, self::INVALID_PASS_STATUSES, true)
                    && $pass->checked_in_at === null
                    && (string) $pass->status !== 'checked_in'
                    && ! (bool) ($event?->is_cancelled)
                    && ! (bool) ($event?->has_ended),
            ]);

            // Avoid duplicating the complete commerce graph in the wallet payload.
            $pass->unsetRelation('orderItem');
        });

        $summary = [
            'total' => $passes->count(),
            'valid' => $passes->filter(fn (EventPass $pass) => ! in_array((string) $pass->status, self::INVALID_PASS_STATUSES, true))->count(),
            'upcoming' => $passes->filter(fn (EventPass $pass) => $pass->event?->temporal_status === 'future' && ! $pass->event?->is_cancelled)->count(),
            'happening_now' => $passes->filter(fn (EventPass $pass) => $pass->event?->temporal_status === 'ongoing' && ! $pass->event?->is_cancelled)->count(),
            'used' => $passes->filter(fn (EventPass $pass) => $pass->checked_in_at !== null || (string) $pass->status === 'checked_in')->count(),
            'past' => $passes->filter(fn (EventPass $pass) => $pass->event?->temporal_status === 'past')->count(),
            'cancelled' => $passes->filter(fn (EventPass $pass) => (string) $pass->status === 'cancelled' || (bool) ($pass->event?->is_cancelled))->count(),
            'refunded' => $passes->where('status', 'refunded')->count(),
            'complimentary' => $passes->filter(fn (EventPass $pass) => (bool) $pass->getAttribute('is_complimentary'))->count(),
        ];

        return ['passes' => $passes, 'summary' => $summary];
    }

    public function show(User $user, int $passId): array
    {
        $appId = $this->context->id();
        $pass = EventPass::query()
            ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
            ->with([
                'ticket',
                'event.production',
                'user:id,first_name,last_name,email,avatar',
                'orderItem.order.payments' => fn ($query) => $query->latest('id'),
            ])
            ->findOrFail($passId);

        $isHolder = (int) $pass->user_id === (int) $user->id;
        if (! $isHolder) {
            $this->manageableEvent((int) $pass->event_id, $user);
            $pass->makeHidden('token');
        }

        $orderItem = $pass->orderItem;
        $order = $orderItem?->order;
        $pass->setAttribute('is_complimentary', (float) ($pass->ticket?->price ?? 0) <= 0);
        $pass->setAttribute('purchase', $order ? [
            'id' => (int) $order->id,
            'public_id' => (string) $order->public_id,
            'status' => (string) $order->status,
            'payment_method' => $order->payment_method,
            'total' => $order->total,
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'platform_fee' => $order->platform_fee,
            'processor_fee' => $order->processor_fee,
            'paid_at' => optional($order->paid_at)->toIso8601String(),
            'purchased_at' => optional($order->created_at)->toIso8601String(),
            'line_unit_price' => $orderItem?->unit_price,
            'line_subtotal' => $orderItem?->subtotal,
            'latest_payment_status' => optional($order->payments->first())->status,
        ] : null);
        $pass->setAttribute('wallet_state', [
            'event_status' => $pass->event?->temporal_status,
            'event_cancelled' => (bool) ($pass->event?->is_cancelled),
            'checked_in' => $pass->checked_in_at !== null || (string) $pass->status === 'checked_in',
            'transferable' => $isHolder
                && ! in_array((string) $pass->status, self::INVALID_PASS_STATUSES, true)
                && $pass->checked_in_at === null
                && (string) $pass->status !== 'checked_in'
                && ! (bool) ($pass->event?->is_cancelled)
                && ! (bool) ($pass->event?->has_ended),
        ]);
        $pass->unsetRelation('orderItem');

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

    /**
     * Eventos que o operador pode efetivamente validar neste aplicativo.
     * Nunca devolve eventos de outras aplicações ou de produções externas.
     */
    public function operableEvents(User $operator, ?string $search = null, int $perPage = 100): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $appId = $this->context->id();
        $query = Event::query()
            ->where('app_id', $appId)
            ->whereHas('production', fn ($production) => $production->where('app_id', $appId))
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->with('production:id,app_id,name,slug')
            ->select(['id', 'app_id', 'production_id', 'title', 'slug', 'start_date', 'end_date', 'is_published', 'is_cancelled']);

        if (! $this->hasGlobalCheckinPermission($operator, $appId)) {
            $scope = $this->operatorScope($operator, $appId);
            $query->where(function ($allowed) use ($operator, $scope) {
                $allowed->whereHas('production', fn ($production) => $production->where('user_id', $operator->id));
                if ($scope['event_ids']) {
                    $allowed->orWhereIn('id', $scope['event_ids']);
                }
                if ($scope['production_ids']) {
                    $allowed->orWhereIn('production_id', $scope['production_ids']);
                }
            });
        }

        if ($search = trim((string) $search)) {
            $query->where(function ($match) use ($search) {
                $match->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('production', fn ($production) => $production->where('name', 'like', '%'.$search.'%'));
            });
        }

        return $query->orderByDesc('start_date')->orderByDesc('id')
            ->paginate(max(1, min($perPage, 100)));
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

    private function hasGlobalCheckinPermission(User $operator, int $appId): bool
    {
        return $this->applicationAdmin->isRoot($operator)
            || $this->applicationAdmin->hasPermission($operator, $appId, 'checkin.manage');
    }

    private function canOperateEvent(User $operator, Event $event, int $appId): bool
    {
        $production = $event->production;

        if ($this->hasGlobalCheckinPermission($operator, $appId)) {
            return true;
        }

        if ($production && (int) $production->user_id === (int) $operator->id) {
            return true;
        }

        $scope = $this->operatorScope($operator, $appId);

        return in_array((int) $event->id, $scope['event_ids'], true)
            || ($production && in_array((int) $production->id, $scope['production_ids'], true));
    }

    private function operatorScope(User $operator, int $appId): array
    {
        $empty = ['event_ids' => [], 'production_ids' => []];
        if (! $operator->hasPermission('ticket_checkin') && ! $operator->hasPermission('event_checkin')) {
            return $empty;
        }

        $membership = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $operator->id)
            ->where('status', 'active')
            ->first();
        if (! $membership) {
            return $empty;
        }

        $metadata = $membership->metadata ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true);
        } elseif (is_object($metadata)) {
            $metadata = (array) $metadata;
        }
        if (! is_array($metadata)) {
            return $empty;
        }

        return [
            'event_ids' => array_map('intval', is_array($metadata['event_ids'] ?? null) ? $metadata['event_ids'] : []),
            'production_ids' => array_map('intval', is_array($metadata['production_ids'] ?? null) ? $metadata['production_ids'] : []),
        ];
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
