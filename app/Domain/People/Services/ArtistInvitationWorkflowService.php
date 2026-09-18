<?php

namespace App\Domain\People\Services;

use App\Domain\Messaging\Services\WebPushService;
use App\Mail\ArtistEventInvitationMail;
use App\Models\Application;
use App\Models\Artist;
use App\Models\Event;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\EventLineupNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ArtistInvitationWorkflowService
{
    private const ACTIVE_STATUSES = ['pending', 'pending_external', 'pending_change'];
    private const MATERIAL_FIELDS = ['participation_type', 'scheduled_at', 'stage', 'fee_cents'];
    private const RESEND_COOLDOWN_MINUTES = 15;

    public function __construct(
        private readonly ArtistIdentityService $identity,
        private readonly AppNotificationService $notifications,
        private readonly WebPushService $push,
        private readonly EventLineupNotificationService $lineupNotifications,
    ) {
    }

    public function inviteByIdentifier(
        int $appId,
        int $eventId,
        User $actor,
        string $identifier,
        array $participation
    ): array {
        $event = $this->ownedEvent($appId, $eventId, $actor);
        $user = $this->identity->resolveUser($identifier);

        if ($user) {
            return $this->inviteRegistered($appId, $event, $actor, $user, $participation);
        }

        $email = mb_strtolower(trim($identifier));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'identifier' => ['Usuário não encontrado. Informe um e-mail válido para enviar o convite de cadastro.'],
            ]);
        }

        return $this->inviteExternal($appId, $event, $actor, $email, $participation);
    }

    public function inviteRegistered(
        int $appId,
        Event $event,
        User $actor,
        User $user,
        array $participation
    ): array {
        $result = DB::transaction(function () use ($appId, $event, $actor, $user, $participation) {
            $artist = $this->identity->getOrCreate($appId, $user, $actor, $event, 'producer_event');
            $existingPivot = DB::table('event_artist')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->where('artist_id', $artist->id)
                ->lockForUpdate()
                ->first();

            $existingInvitation = DB::table('artist_invitations')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->where('artist_id', $artist->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $token = $existingInvitation?->token ?: $existingPivot?->invite_token ?: Str::random(48);
            $status = $existingPivot?->status === 'confirmed' ? 'confirmed' : 'pending';
            $expiresAt = $this->expiresAt($event);
            $payload = $this->participationPayload($participation);

            $pivot = [
                'app_id' => $appId,
                ...$payload,
                'status' => $status === 'confirmed' ? 'pending_change' : 'pending',
                'invited_by_user_id' => $actor->id,
                'invited_at' => $existingPivot?->invited_at ?: now(),
                'responded_at' => null,
                'response_user_id' => null,
                'decline_reason' => null,
                'cancelled_at' => null,
                'invite_token' => $token,
                'updated_at' => now(),
            ];

            if ($existingPivot) {
                DB::table('event_artist')
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('artist_id', $artist->id)
                    ->update($pivot);
            } else {
                DB::table('event_artist')->insert([
                    ...$pivot,
                    'event_id' => $event->id,
                    'artist_id' => $artist->id,
                    'payment_status' => 'not_applicable',
                    'created_at' => now(),
                ]);
            }

            $invitationData = [
                'artist_id' => $artist->id,
                'invited_user_id' => $user->id,
                'invited_by_user_id' => $actor->id,
                'identifier_type' => 'email',
                'identifier_hash' => hash('sha256', mb_strtolower(trim((string) $user->email))),
                'identifier_hint' => $this->maskEmail($user->email),
                'recipient_email_encrypted' => Crypt::encryptString(mb_strtolower(trim((string) $user->email))),
                'status' => $status === 'confirmed' ? 'pending_change' : 'pending',
                'token' => $token,
                'expires_at' => $expiresAt,
                'payload' => json_encode($payload),
                'source' => 'registered_user',
                'responded_at' => null,
                'responded_by_user_id' => null,
                'decline_reason' => null,
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'updated_at' => now(),
            ];

            if ($existingInvitation) {
                DB::table('artist_invitations')->where('id', $existingInvitation->id)->update($invitationData);
                $invitationId = (int) $existingInvitation->id;
            } else {
                $invitationId = (int) DB::table('artist_invitations')->insertGetId([
                    'app_id' => $appId,
                    'event_id' => $event->id,
                    ...$invitationData,
                    'email_status' => 'not_sent',
                    'send_attempts' => 0,
                    'reminder_count' => 0,
                    'important_change_count' => $status === 'confirmed' ? 1 : 0,
                    'created_at' => now(),
                ]);
            }

            $this->audit($appId, $artist->id, $event->id, $actor->id, 'artist_invited', $existingPivot ? (array) $existingPivot : null, $pivot);
            $this->track($appId, $invitationId, $event->id, $artist->id, $user->id, 'invite_created', 'registered_user');

            return compact('artist', 'invitationId', 'token');
        }, 3);

        $this->deliverInvitation(
            $appId,
            $result['invitationId'],
            $event,
            $actor,
            $user,
            $result['artist'],
            false
        );

        return [
            'message' => 'Usuário localizado. O convite foi enviado e a participação ficará aguardando aceite.',
            'artist' => $result['artist']->fresh(),
            'invitation_id' => $result['invitationId'],
            'participation_status' => 'pending',
            'invitation_sent' => true,
            'external_invitation' => false,
        ];
    }

    public function inviteExternal(
        int $appId,
        Event $event,
        User $actor,
        string $email,
        array $participation
    ): array {
        $email = mb_strtolower(trim($email));
        $hash = hash('sha256', $email);
        $payload = $this->participationPayload($participation);
        $expiresAt = $this->expiresAt($event);

        $invitation = DB::transaction(function () use ($appId, $event, $actor, $email, $hash, $payload, $expiresAt) {
            $existing = DB::table('artist_invitations')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->where('identifier_type', 'email')
                ->where('identifier_hash', $hash)
                ->whereIn('status', ['pending_external', 'pending'])
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                })
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $token = $existing?->token ?: Str::random(48);
            $data = [
                'invited_by_user_id' => $actor->id,
                'identifier_type' => 'email',
                'identifier_hash' => $hash,
                'identifier_hint' => $this->maskEmail($email),
                'recipient_email_encrypted' => Crypt::encryptString($email),
                'status' => 'pending_external',
                'token' => $token,
                'expires_at' => $expiresAt,
                'payload' => json_encode($payload),
                'source' => 'external_email',
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('artist_invitations')->where('id', $existing->id)->update($data);
                $id = (int) $existing->id;
            } else {
                $id = (int) DB::table('artist_invitations')->insertGetId([
                    'app_id' => $appId,
                    'event_id' => $event->id,
                    'artist_id' => null,
                    'invited_user_id' => null,
                    ...$data,
                    'email_status' => 'not_sent',
                    'send_attempts' => 0,
                    'reminder_count' => 0,
                    'important_change_count' => 0,
                    'created_at' => now(),
                ]);
            }

            $this->track($appId, $id, $event->id, null, null, 'invite_created', 'external_email');

            return DB::table('artist_invitations')->where('id', $id)->first();
        }, 3);

        $sent = $this->deliverExternalInvitation($appId, $invitation, $event, $actor, $email);

        return [
            'message' => $sent
                ? 'A pessoa ainda não possui conta. O convite de cadastro foi enviado por e-mail.'
                : 'O convite foi criado, mas o e-mail não pôde ser enviado agora.',
            'external_invitation' => true,
            'invitation_sent' => $sent,
            'invitation_id' => (int) $invitation->id,
        ];
    }

    public function claimPending(int $appId, User $user): array
    {
        abort_unless($user->email_verified_at, 403, 'Confirme seu e-mail antes de recuperar convites artísticos.');

        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '') {
            return ['claimed' => 0, 'message' => 'Nenhum convite artístico pendente encontrado.'];
        }

        $ids = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('identifier_type', 'email')
            ->where('identifier_hash', hash('sha256', $email))
            ->where('status', 'pending_external')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->orderBy('id')
            ->limit(20)
            ->pluck('id');

        $claimed = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($appId, $id, $user, &$claimed) {
                $invitation = DB::table('artist_invitations')->where('id', $id)->lockForUpdate()->first();
                if (! $invitation || $invitation->status !== 'pending_external') return;

                $event = Event::query()->where('app_id', $appId)->with('production')->find($invitation->event_id);
                if (! $event || $event->is_cancelled || $this->isExpired($invitation, $event)) {
                    DB::table('artist_invitations')->where('id', $id)->update(['status' => 'expired', 'updated_at' => now()]);
                    return;
                }

                $actor = User::query()->find($invitation->invited_by_user_id) ?: $user;
                $artist = $this->identity->getOrCreate($appId, $user, $actor, $event, 'external_invitation');
                $payload = json_decode((string) ($invitation->payload ?? '{}'), true) ?: [];
                $existingPivot = DB::table('event_artist')
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('artist_id', $artist->id)
                    ->first();

                $pivot = [
                    'app_id' => $appId,
                    ...$this->participationPayload($payload),
                    'status' => 'pending',
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $existingPivot?->invited_at ?: ($invitation->created_at ?: now()),
                    'responded_at' => null,
                    'response_user_id' => null,
                    'decline_reason' => null,
                    'cancelled_at' => null,
                    'invite_token' => $invitation->token,
                    'updated_at' => now(),
                ];

                if ($existingPivot) {
                    DB::table('event_artist')->where('app_id', $appId)->where('event_id', $event->id)->where('artist_id', $artist->id)->update($pivot);
                } else {
                    DB::table('event_artist')->insert([
                        ...$pivot,
                        'event_id' => $event->id,
                        'artist_id' => $artist->id,
                        'payment_status' => 'not_applicable',
                        'created_at' => now(),
                    ]);
                }

                DB::table('artist_invitations')->where('id', $id)->update([
                    'artist_id' => $artist->id,
                    'invited_user_id' => $user->id,
                    'status' => 'pending',
                    'source' => 'external_email_claimed',
                    'updated_at' => now(),
                ]);

                $this->notifications->sendToUser($appId, (int) $user->id, [
                    'type' => 'artist_event_invitation',
                    'title' => 'Convite artístico aguardando sua resposta',
                    'message' => ($event->production?->name ?: 'Uma produção').' convidou você para '.$event->title.'.',
                    'reference_type' => 'artist_invitation',
                    'reference_id' => (int) $id,
                    'reference_url' => '/artist/invitations/'.$invitation->token,
                    'data' => ['event_id' => $event->id, 'artist_id' => $artist->id, 'status' => 'pending'],
                    'send_email' => false,
                ]);

                $this->push->sendToUser($appId, (int) $user->id, [
                    'title' => 'Convite artístico',
                    'body' => 'Você tem um convite para '.$event->title.'.',
                    'url' => '/artist/invitations/'.$invitation->token,
                    'tag' => 'artist-invitation-'.$id,
                ]);

                $this->track($appId, (int) $id, $event->id, $artist->id, $user->id, 'invite_claimed', 'external_email');
                $claimed++;
            }, 3);
        }

        return [
            'claimed' => $claimed,
            'message' => $claimed > 0 ? 'Convites artísticos vinculados à sua conta e aguardando sua resposta.' : 'Nenhum convite artístico pendente encontrado.',
        ];
    }

    public function showForUser(int $appId, string $token, User $user): array
    {
        $invitation = $this->invitationByToken($appId, $token);
        $event = Event::query()->where('app_id', $appId)->with('production')->findOrFail($invitation->event_id);

        $this->expireIfNeeded($invitation, $event);
        $invitation = DB::table('artist_invitations')->where('id', $invitation->id)->first();

        $this->authorizeResponder($appId, $invitation, $user);

        if (! $invitation->viewed_at) {
            DB::table('artist_invitations')->where('id', $invitation->id)->update(['viewed_at' => now(), 'updated_at' => now()]);
            $this->track($appId, (int) $invitation->id, $event->id, $invitation->artist_id, $user->id, 'invite_viewed', 'app');
        }

        $artist = $invitation->artist_id ? Artist::query()->find($invitation->artist_id) : null;
        $pivot = $artist
            ? DB::table('event_artist')->where('app_id', $appId)->where('event_id', $event->id)->where('artist_id', $artist->id)->first()
            : null;

        return $this->invitationPayload($appId, $invitation, $event, $artist, $pivot, $user);
    }

    public function respondByToken(
        int $appId,
        string $token,
        User $user,
        string $decision,
        ?string $declineReason,
        ?string $ip,
        ?string $userAgent
    ): array {
        abort_unless(in_array($decision, ['accept', 'reject'], true), 422, 'Resposta inválida.');

        $invitation = $this->invitationByToken($appId, $token);
        $event = Event::query()->where('app_id', $appId)->with('production')->findOrFail($invitation->event_id);
        $this->expireIfNeeded($invitation, $event);
        $invitation = DB::table('artist_invitations')->where('id', $invitation->id)->first();
        $this->authorizeResponder($appId, $invitation, $user);

        abort_unless(in_array($invitation->status, ['pending', 'pending_change'], true), 422, 'Este convite não está mais aguardando resposta.');
        abort_if($event->is_cancelled, 422, 'Este evento foi cancelado.');
        abort_if($event->start_date && Carbon::parse($event->start_date)->lte(now()), 422, 'O evento já começou e o convite não pode mais ser aceito.');

        $status = $decision === 'accept' ? 'confirmed' : 'declined';
        $invitationStatus = $decision === 'accept' ? 'accepted' : 'declined';

        DB::transaction(function () use ($appId, $invitation, $event, $user, $status, $invitationStatus, $declineReason, $ip, $userAgent) {
            $locked = DB::table('artist_invitations')->where('id', $invitation->id)->lockForUpdate()->first();
            abort_unless($locked && in_array($locked->status, ['pending', 'pending_change'], true), 422, 'Este convite já foi respondido.');

            DB::table('artist_invitations')->where('id', $locked->id)->update([
                'status' => $invitationStatus,
                'responded_at' => now(),
                'responded_by_user_id' => $user->id,
                'decline_reason' => $status === 'declined' ? Str::limit(trim((string) $declineReason), 500, '') ?: null : null,
                'response_ip_hash' => $ip ? hash('sha256', $ip) : null,
                'response_user_agent_hash' => $userAgent ? hash('sha256', $userAgent) : null,
                'updated_at' => now(),
            ]);

            if ($locked->artist_id) {
                DB::table('event_artist')
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('artist_id', $locked->artist_id)
                    ->update([
                        'status' => $status,
                        'responded_at' => now(),
                        'response_user_id' => $user->id,
                        'decline_reason' => $status === 'declined' ? Str::limit(trim((string) $declineReason), 500, '') ?: null : null,
                        'cancelled_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            $this->audit(
                $appId,
                $locked->artist_id,
                $event->id,
                $user->id,
                $status === 'confirmed' ? 'invitation_confirmed' : 'invitation_declined',
                (array) $locked,
                ['status' => $invitationStatus, 'decline_reason' => $declineReason]
            );
        }, 3);

        $this->track($appId, (int) $invitation->id, $event->id, $invitation->artist_id, $user->id, $status === 'confirmed' ? 'invite_accepted' : 'invite_rejected', 'app');
        $this->notifyProducerOfResponse($appId, $event, $invitation, $user, $status, $declineReason);

        if ($status === 'confirmed') {
            $this->notifications->sendToUser($appId, (int) $user->id, [
                'type' => 'artist_event_confirmed',
                'title' => 'Participação confirmada',
                'message' => 'Sua participação em '.$event->title.' foi confirmada.',
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/artist/invitations/'.$token,
                'data' => ['event_id' => $event->id, 'status' => 'confirmed'],
            ]);
            $this->push->sendToUser($appId, (int) $user->id, [
                'title' => 'Participação confirmada',
                'body' => 'Você confirmou presença em '.$event->title.'.',
                'url' => '/artist/invitations/'.$token,
                'tag' => 'artist-invitation-'.$invitation->id,
            ]);
            $this->lineupNotifications->notifyPublishedEvent($event->fresh('artists'));
        }

        return [
            'message' => $status === 'confirmed' ? 'Participação confirmada.' : 'Convite recusado.',
            'status' => $status,
        ];
    }

    public function respondByEventArtist(
        int $appId,
        int $eventId,
        int $artistId,
        User $user,
        string $decision,
        ?string $declineReason,
        ?string $ip,
        ?string $userAgent
    ): array {
        $invitation = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('event_id', $eventId)
            ->where('artist_id', $artistId)
            ->whereIn('status', ['pending', 'pending_change'])
            ->latest('id')
            ->first();

        abort_unless($invitation, 404, 'Convite pendente não encontrado.');

        return $this->respondByToken($appId, $invitation->token, $user, $decision, $declineReason, $ip, $userAgent);
    }

    public function mine(int $appId, User $user, int $perPage = 30): array
    {
        $artistIds = Artist::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->pluck('id');

        $managedArtistIds = DB::table('artist_managers')
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->pluck('artist_id');

        $ids = $artistIds->merge($managedArtistIds)->unique()->values();

        $paginator = DB::table('artist_invitations')
            ->join('events', 'events.id', '=', 'artist_invitations.event_id')
            ->leftJoin('establishments', 'establishments.id', '=', 'events.production_id')
            ->leftJoin('artists', 'artists.id', '=', 'artist_invitations.artist_id')
            ->where('artist_invitations.app_id', $appId)
            ->where(function ($query) use ($user, $ids) {
                $query->where('artist_invitations.invited_user_id', $user->id)
                    ->orWhereIn('artist_invitations.artist_id', $ids);
            })
            ->select([
                'artist_invitations.id',
                'artist_invitations.token',
                'artist_invitations.status',
                'artist_invitations.email_status',
                'artist_invitations.expires_at',
                'artist_invitations.responded_at',
                'artist_invitations.decline_reason',
                'artist_invitations.viewed_at',
                'artist_invitations.created_at',
                'events.id as event_id',
                'events.slug as event_slug',
                'events.title as event_title',
                'events.start_date',
                'events.end_date',
                'events.image as event_image',
                'events.venue',
                'events.city',
                'events.uf',
                'establishments.name as production_name',
                'artists.id as artist_id',
                'artists.stage_name',
            ])
            ->orderByRaw("CASE WHEN artist_invitations.status IN ('pending','pending_change') THEN 0 ELSE 1 END")
            ->orderBy('events.start_date')
            ->paginate(min(max($perPage, 1), 100));

        $pendingCount = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->whereIn('status', ['pending', 'pending_change'])
            ->where(function ($query) use ($user, $ids) {
                $query->where('invited_user_id', $user->id)->orWhereIn('artist_id', $ids);
            })
            ->count();

        return ['invitations' => $paginator, 'pending_count' => $pendingCount];
    }

    public function producerEventInvitations(int $appId, int $eventId, User $actor): array
    {
        $event = $this->ownedEvent($appId, $eventId, $actor);

        $rows = DB::table('artist_invitations')
            ->leftJoin('artists', 'artists.id', '=', 'artist_invitations.artist_id')
            ->leftJoin('users', 'users.id', '=', 'artist_invitations.invited_user_id')
            ->where('artist_invitations.app_id', $appId)
            ->where('artist_invitations.event_id', $event->id)
            ->select([
                'artist_invitations.id','artist_invitations.token','artist_invitations.status','artist_invitations.email_status',
                'artist_invitations.identifier_hint','artist_invitations.expires_at','artist_invitations.email_sent_at',
                'artist_invitations.email_opened_at','artist_invitations.email_failed_at','artist_invitations.last_sent_at',
                'artist_invitations.resend_available_at','artist_invitations.send_attempts','artist_invitations.reminder_count',
                'artist_invitations.responded_at','artist_invitations.decline_reason','artist_invitations.important_change_count',
                'artists.id as artist_id','artists.stage_name','artists.photo',
                'users.id as user_id','users.first_name','users.last_name','users.user_name','users.avatar',
            ])
            ->orderBy('artist_invitations.created_at')
            ->get();

        $counts = collect($rows)->countBy(fn ($row) => (string) $row->status)->all();

        $metrics = DB::table('artist_invitation_events')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->map(fn ($value) => (int) $value)
            ->all();

        $accepted = (int) ($metrics['invite_accepted'] ?? 0);
        $viewed = (int) ($metrics['invite_viewed'] ?? 0);
        $created = (int) ($metrics['invite_created'] ?? 0);

        return [
            'event' => ['id' => $event->id, 'title' => $event->title, 'start_date' => $event->start_date],
            'invitations' => $rows,
            'counts' => $counts,
            'metrics' => [
                ...$metrics,
                'acceptance_rate' => $created > 0 ? round(($accepted / $created) * 100, 1) : 0,
                'view_rate' => $created > 0 ? round(($viewed / $created) * 100, 1) : 0,
                'average_response_minutes' => $this->averageResponseMinutes($appId, $event->id),
            ],
        ];
    }

    public function resend(int $appId, int $eventId, int $invitationId, User $actor): array
    {
        $event = $this->ownedEvent($appId, $eventId, $actor);
        $invitation = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->find($invitationId);

        abort_unless($invitation, 404, 'Convite não encontrado.');
        abort_unless(in_array($invitation->status, self::ACTIVE_STATUSES, true), 422, 'Somente convites pendentes podem ser reenviados.');

        if ($invitation->resend_available_at && Carbon::parse($invitation->resend_available_at)->isFuture()) {
            abort(429, 'Aguarde alguns minutos antes de reenviar este convite.');
        }

        $user = $invitation->invited_user_id ? User::query()->find($invitation->invited_user_id) : null;
        $artist = $invitation->artist_id ? Artist::query()->find($invitation->artist_id) : null;

        $sent = $user
            ? $this->deliverInvitation($appId, $invitation->id, $event, $actor, $user, $artist, true)
            : $this->deliverExternalInvitation($appId, $invitation, $event, $actor, $this->decryptRecipientEmail($invitation));

        if ($sent) {
            $this->track($appId, (int) $invitation->id, $event->id, $artist?->id, $user?->id, 'invite_resent', 'producer');
        }

        return [
            'message' => $sent ? 'Convite reenviado.' : 'O convite continua pendente, mas o e-mail não pôde ser reenviado.',
            'sent' => $sent,
        ];
    }

    public function cancel(int $appId, int $eventId, int $invitationId, User $actor, ?string $reason = null): array
    {
        $event = $this->ownedEvent($appId, $eventId, $actor);
        $invitation = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->find($invitationId);

        abort_unless($invitation, 404, 'Convite não encontrado.');
        abort_unless(! in_array($invitation->status, ['cancelled_by_producer', 'expired'], true), 422, 'Este convite já não está ativo.');

        DB::transaction(function () use ($appId, $event, $invitation, $actor, $reason) {
            DB::table('artist_invitations')->where('id', $invitation->id)->update([
                'status' => 'cancelled_by_producer',
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->id,
                'decline_reason' => Str::limit(trim((string) $reason), 500, '') ?: null,
                'updated_at' => now(),
            ]);

            if ($invitation->artist_id) {
                DB::table('event_artist')
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('artist_id', $invitation->artist_id)
                    ->update([
                        'status' => 'cancelled_by_producer',
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $this->audit($appId, $invitation->artist_id, $event->id, $actor->id, 'invitation_cancelled_by_producer', (array) $invitation, ['reason' => $reason]);
        }, 3);

        if ($invitation->invited_user_id) {
            $this->notifications->sendToUser($appId, (int) $invitation->invited_user_id, [
                'type' => 'artist_event_invitation_cancelled',
                'title' => 'Convite artístico cancelado',
                'message' => 'A produção cancelou seu convite para '.$event->title.'.',
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/artist/invitations/'.$invitation->token,
                'data' => ['event_id' => $event->id, 'status' => 'cancelled_by_producer'],
            ]);
            $this->push->sendToUser($appId, (int) $invitation->invited_user_id, [
                'title' => 'Convite cancelado',
                'body' => 'Seu convite para '.$event->title.' foi cancelado.',
                'url' => '/artist/invitations/'.$invitation->token,
                'tag' => 'artist-invitation-'.$invitation->id,
            ]);
        }

        $this->track($appId, (int) $invitation->id, $event->id, $invitation->artist_id, $invitation->invited_user_id, 'invite_cancelled', 'producer');

        return ['message' => 'Convite cancelado.', 'status' => 'cancelled_by_producer'];
    }

    public function updateParticipation(int $appId, int $eventId, int $artistId, User $actor, array $changes): array
    {
        $event = $this->ownedEvent($appId, $eventId, $actor);
        $pivot = DB::table('event_artist')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('artist_id', $artistId)
            ->first();

        abort_unless($pivot, 404, 'Participação artística não encontrada.');

        $allowed = collect($changes)->only([
            'participation_type','description','sort_order','scheduled_at','stage','is_headliner','fee_cents','payment_status','private_notes',
        ])->all();

        $materialChanges = [];
        foreach (self::MATERIAL_FIELDS as $field) {
            if (array_key_exists($field, $allowed) && $this->normalizeComparable($allowed[$field]) !== $this->normalizeComparable($pivot->{$field} ?? null)) {
                $materialChanges[$field] = ['from' => $pivot->{$field} ?? null, 'to' => $allowed[$field]];
            }
        }

        $needsReaccept = $materialChanges !== [] && ($pivot->status ?? null) === 'confirmed';
        if ($needsReaccept) {
            $allowed['status'] = 'pending_change';
            $allowed['responded_at'] = null;
            $allowed['response_user_id'] = null;
            $allowed['last_material_change_at'] = now();
        }
        $allowed['updated_at'] = now();

        DB::transaction(function () use ($appId, $event, $artistId, $pivot, $allowed, $actor, $needsReaccept, $materialChanges) {
            DB::table('event_artist')->where('app_id', $appId)->where('event_id', $event->id)->where('artist_id', $artistId)->update($allowed);

            if ($needsReaccept) {
                $nextParticipation = $this->participationPayload(array_merge((array) $pivot, $allowed));
                $nextParticipation['_changed_fields'] = $materialChanges;

                DB::table('artist_invitations')
                    ->where('app_id', $appId)
                    ->where('event_id', $event->id)
                    ->where('artist_id', $artistId)
                    ->whereIn('status', ['accepted', 'pending', 'pending_change'])
                    ->latest('id')
                    ->limit(1)
                    ->update([
                        'status' => 'pending_change',
                        'payload' => json_encode($nextParticipation),
                        'responded_at' => null,
                        'responded_by_user_id' => null,
                        'important_change_count' => DB::raw('important_change_count + 1'),
                        'last_material_change_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $this->audit($appId, $artistId, $event->id, $actor->id, $needsReaccept ? 'participation_material_change' : 'participation_details_updated', (array) $pivot, ['changes' => $allowed, 'material' => $materialChanges]);
        }, 3);

        if ($needsReaccept) {
            $invitation = DB::table('artist_invitations')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->where('artist_id', $artistId)
                ->where('status', 'pending_change')
                ->latest('id')
                ->first();

            if ($invitation) {
                $artist = Artist::query()->find($artistId);
                $user = $invitation->invited_user_id ? User::query()->find($invitation->invited_user_id) : $artist?->user;
                if ($user) {
                    $this->deliverInvitation($appId, $invitation->id, $event, $actor, $user, $artist, true, array_keys($materialChanges));
                }
                $this->track($appId, (int) $invitation->id, $event->id, $artistId, $user?->id, 'invite_reconfirmation_requested', 'material_change', ['fields' => array_keys($materialChanges)]);
            }
        }

        return [
            'message' => $needsReaccept
                ? 'Alteração importante salva. O artista precisa aceitar novamente.'
                : 'Participação atualizada.',
            'requires_reacceptance' => $needsReaccept,
            'material_changes' => array_keys($materialChanges),
            'artists' => $event->artists()->orderBy('event_artist.sort_order')->get(),
        ];
    }

    public function cancelForEvent(Event $event, array $changedFields = []): void
    {
        $appId = (int) $event->app_id;

        if ($event->is_cancelled) {
            $invitations = DB::table('artist_invitations')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->whereIn('status', ['pending','pending_change','accepted'])
                ->get();

            foreach ($invitations as $invitation) {
                DB::table('artist_invitations')->where('id', $invitation->id)->update([
                    'status' => 'event_cancelled',
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($invitation->artist_id) {
                    DB::table('event_artist')->where('app_id', $appId)->where('event_id', $event->id)->where('artist_id', $invitation->artist_id)->update([
                        'status' => 'event_cancelled',
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                if ($invitation->invited_user_id) {
                    $this->notifications->sendToUser($appId, (int) $invitation->invited_user_id, [
                        'type' => 'artist_event_cancelled',
                        'title' => 'Evento cancelado',
                        'message' => $event->title.' foi cancelado.',
                        'reference_type' => 'event',
                        'reference_id' => $event->id,
                        'reference_url' => '/artist/invitations/'.$invitation->token,
                        'data' => ['event_id' => $event->id, 'status' => 'event_cancelled'],
                    ]);
                    $this->push->sendToUser($appId, (int) $invitation->invited_user_id, [
                        'title' => 'Evento cancelado',
                        'body' => $event->title.' foi cancelado.',
                        'url' => '/artist/invitations/'.$invitation->token,
                        'tag' => 'artist-invitation-'.$invitation->id,
                    ]);
                }
                $this->track($appId, (int) $invitation->id, $event->id, $invitation->artist_id, $invitation->invited_user_id, 'event_cancelled', 'event_update');
            }
            return;
        }

        $relevant = array_values(array_intersect($changedFields, ['start_date','end_date','venue','address','address_number','neighborhood','city','uf','formatted_address']));
        if ($relevant === []) return;

        $accepted = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('status', 'accepted')
            ->get();

        foreach ($accepted as $invitation) {
            $requiresReaccept = in_array('start_date', $relevant, true);
            if ($requiresReaccept) {
                DB::table('artist_invitations')->where('id', $invitation->id)->update([
                    'status' => 'pending_change',
                    'responded_at' => null,
                    'important_change_count' => DB::raw('important_change_count + 1'),
                    'last_material_change_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($invitation->artist_id) {
                    DB::table('event_artist')->where('app_id', $appId)->where('event_id', $event->id)->where('artist_id', $invitation->artist_id)->update([
                        'status' => 'pending_change',
                        'responded_at' => null,
                        'last_material_change_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($invitation->invited_user_id) {
                $this->notifications->sendToUser($appId, (int) $invitation->invited_user_id, [
                    'type' => $requiresReaccept ? 'artist_event_reconfirmation' : 'artist_event_updated',
                    'title' => $requiresReaccept ? 'Evento alterado: confirme novamente' : 'Evento atualizado',
                    'message' => $event->title.' teve alterações em '.implode(', ', $relevant).'.',
                    'reference_type' => 'artist_invitation',
                    'reference_id' => $invitation->id,
                    'reference_url' => '/artist/invitations/'.$invitation->token,
                    'data' => ['event_id' => $event->id, 'changed_fields' => $relevant, 'requires_reacceptance' => $requiresReaccept],
                ]);
            }
        }
    }

    public function dispatchDueReminders(int $limit = 100): array
    {
        $rows = DB::table('artist_invitations')
            ->whereIn('status', ['pending', 'pending_external', 'pending_change'])
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where('created_at', '<=', now()->subHours(48))
            ->where('reminder_count', '<', 2)
            ->where(function ($query) {
                $query->whereNull('last_reminder_at')->orWhere('last_reminder_at', '<=', now()->subHours(24));
            })
            ->orderBy('id')
            ->limit(min(max($limit, 1), 500))
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($rows as $invitation) {
            $event = Event::query()->with('production')->find($invitation->event_id);
            if (! $event || $event->is_cancelled || ($event->start_date && Carbon::parse($event->start_date)->lte(now()))) {
                DB::table('artist_invitations')->where('id', $invitation->id)->update(['status' => 'expired', 'updated_at' => now()]);
                continue;
            }

            $actor = User::query()->find($invitation->invited_by_user_id);
            if (! $actor) continue;

            $ok = false;
            if ($invitation->invited_user_id) {
                $user = User::query()->find($invitation->invited_user_id);
                $artist = $invitation->artist_id ? Artist::query()->find($invitation->artist_id) : null;
                if ($user) {
                    $ok = $this->deliverInvitation((int) $invitation->app_id, (int) $invitation->id, $event, $actor, $user, $artist, true);
                }
            } else {
                $email = $this->decryptRecipientEmail($invitation);
                if ($email) $ok = $this->deliverExternalInvitation((int) $invitation->app_id, $invitation, $event, $actor, $email);
            }

            DB::table('artist_invitations')->where('id', $invitation->id)->update([
                'reminder_count' => DB::raw('reminder_count + 1'),
                'last_reminder_at' => now(),
                'updated_at' => now(),
            ]);

            $this->track((int) $invitation->app_id, (int) $invitation->id, (int) $invitation->event_id, $invitation->artist_id, $invitation->invited_user_id, 'invite_reminder_sent', 'scheduler');
            $ok ? $sent++ : $failed++;
        }

        return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    public function trackOpen(int $appId, string $token): void
    {
        $invitation = DB::table('artist_invitations')->where('app_id', $appId)->where('token', $token)->first();
        if (! $invitation) return;

        if (! $invitation->email_opened_at) {
            DB::table('artist_invitations')->where('id', $invitation->id)->update([
                'email_status' => 'opened',
                'email_opened_at' => now(),
                'updated_at' => now(),
            ]);
            $this->track($appId, (int) $invitation->id, (int) $invitation->event_id, $invitation->artist_id, $invitation->invited_user_id, 'email_opened', 'email');
        }
    }

    public function recordEmailDeliveryEvent(int $appId, string $token, string $status, string $signature): array
    {
        $status = mb_strtolower(trim($status));
        abort_unless(in_array($status, ['delivered', 'bounced', 'failed'], true), 422, 'Status de entrega inválido.');

        $secret = trim((string) config('services.artist_invitation_email.webhook_secret'));
        abort_if($secret === '', 503, 'Webhook de entrega de e-mail não configurado.');

        $expected = hash_hmac('sha256', $token.'|'.$status, $secret);
        abort_unless(hash_equals($expected, trim($signature)), 403, 'Assinatura de webhook inválida.');

        $invitation = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('token', $token)
            ->first();
        abort_unless($invitation, 404, 'Convite não encontrado.');

        $updates = [
            'email_status' => $status,
            'updated_at' => now(),
        ];

        if ($status === 'delivered') {
            $updates['email_delivered_at'] = now();
            $updates['email_failed_at'] = null;
            $updates['last_email_error'] = null;
        } else {
            $updates['email_failed_at'] = now();
        }

        DB::table('artist_invitations')->where('id', $invitation->id)->update($updates);
        $this->track(
            $appId,
            (int) $invitation->id,
            (int) $invitation->event_id,
            $invitation->artist_id,
            $invitation->invited_user_id,
            'email_'.$status,
            'provider_webhook'
        );

        return ['updated' => true, 'email_status' => $status];
    }

    public function calendarForUser(int $appId, string $token, User $user): string
    {
        $payload = $this->showForUser($appId, $token, $user);
        $event = $payload['event'];
        $start = Carbon::parse($event['start_date'])->utc()->format('Ymd\THis\Z');
        $end = Carbon::parse($event['end_date'] ?: $event['start_date'])->utc()->format('Ymd\THis\Z');
        $uid = 'artist-invitation-'.$payload['invitation']['id'].'@'.parse_url(config('app.url'), PHP_URL_HOST);
        $location = trim((string) ($event['formatted_address'] ?: $event['venue'] ?: $event['city']));

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Peter Tecnet//Artist Invitation//PT-BR',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:'.$this->icsEscape($uid),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$this->icsEscape($event['title']),
            'LOCATION:'.$this->icsEscape($location),
            'DESCRIPTION:'.$this->icsEscape('Participação artística confirmada.'),
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function deliverInvitation(
        int $appId,
        int $invitationId,
        Event $event,
        User $actor,
        User $recipient,
        ?Artist $artist,
        bool $isReminder,
        array $changedFields = []
    ): bool {
        $invitation = DB::table('artist_invitations')->where('id', $invitationId)->first();
        if (! $invitation || ! trim((string) $recipient->email)) return false;

        $application = Application::query()->find($appId);
        $baseUrl = $this->baseUrl($application);
        $actionUrl = $baseUrl.'/artist/invitations/'.$invitation->token;
        $producerName = $event->production?->name ?: $this->userName($actor);
        $payload = json_decode((string) ($invitation->payload ?? '{}'), true) ?: [];

        $this->notifications->sendToUser($appId, (int) $recipient->id, [
            'type' => $invitation->status === 'pending_change' ? 'artist_event_reconfirmation' : 'artist_event_invitation',
            'title' => $invitation->status === 'pending_change' ? 'Confirme novamente sua participação' : 'Novo convite artístico',
            'message' => $producerName.' convidou você para '.$event->title.'.',
            'reference_type' => 'artist_invitation',
            'reference_id' => $invitation->id,
            'reference_url' => '/artist/invitations/'.$invitation->token,
            'data' => ['event_id' => $event->id, 'artist_id' => $artist?->id, 'status' => $invitation->status],
            'send_email' => false,
        ]);

        $this->push->sendToUser($appId, (int) $recipient->id, [
            'title' => $invitation->status === 'pending_change' ? 'Confirme novamente' : 'Novo convite artístico',
            'body' => $producerName.' convidou você para '.$event->title.'.',
            'url' => '/artist/invitations/'.$invitation->token,
            'tag' => 'artist-invitation-'.$invitation->id,
        ]);

        return $this->sendMail(
            $appId,
            $invitation,
            $recipient->email,
            $event,
            $producerName,
            $actionUrl,
            $payload,
            false,
            $isReminder,
            $changedFields
        );
    }

    private function deliverExternalInvitation(
        int $appId,
        object $invitation,
        Event $event,
        User $actor,
        ?string $email
    ): bool {
        if (! $email) return false;

        $application = Application::query()->find($appId);
        $baseUrl = $this->baseUrl($application);
        $returnTo = '/artist/invitations/'.$invitation->token;
        $actionUrl = $baseUrl.'/register?'.http_build_query([
            'artist_invite' => $invitation->token,
            'from' => $returnTo,
        ]);
        $payload = json_decode((string) ($invitation->payload ?? '{}'), true) ?: [];

        return $this->sendMail(
            $appId,
            $invitation,
            $email,
            $event,
            $event->production?->name ?: $this->userName($actor),
            $actionUrl,
            $payload,
            true,
            (int) ($invitation->reminder_count ?? 0) > 0,
            []
        );
    }

    private function sendMail(
        int $appId,
        object $invitation,
        string $email,
        Event $event,
        string $producerName,
        string $actionUrl,
        array $payload,
        bool $requiresRegistration,
        bool $isReminder,
        array $changedFields
    ): bool {
        try {
            Mail::to($email)->send(new ArtistEventInvitationMail(
                $event->title,
                $producerName,
                $actionUrl,
                $payload['participation_type'] ?? null,
                $payload['scheduled_at'] ?? null,
                $payload['stage'] ?? null,
                [
                    'requires_registration' => $requiresRegistration,
                    'is_reminder' => $isReminder,
                    'is_reconfirmation' => $invitation->status === 'pending_change',
                    'changed_fields' => $changedFields,
                    'event_image' => $event->image,
                    'start_date' => optional($event->start_date)->toIso8601String(),
                    'end_date' => optional($event->end_date)->toIso8601String(),
                    'venue' => $event->venue,
                    'formatted_address' => $event->formatted_address ?: trim(implode(', ', array_filter([$event->address, $event->address_number, $event->neighborhood, $event->city, $event->uf]))),
                    'fee_cents' => $payload['fee_cents'] ?? null,
                    'event_contact_email' => $event->contact_email ?: $event->organizer_email,
                    'event_contact_phone' => $event->contact_phone ?: $event->organizer_phone,
                    'tracking_pixel_url' => rtrim((string) config('app.url'), '/').'/api/v1/apps/'.rawurlencode((string) (Application::query()->find($appId)?->slug ?: $appId)).'/artist-invitations/'.$invitation->token.'/open.gif',
                ]
            ));

            DB::table('artist_invitations')->where('id', $invitation->id)->update([
                'email_status' => 'sent',
                'email_sent_at' => now(),
                'email_failed_at' => null,
                'last_email_error' => null,
                'last_sent_at' => now(),
                'send_attempts' => DB::raw('send_attempts + 1'),
                'resend_available_at' => now()->addMinutes(self::RESEND_COOLDOWN_MINUTES),
                'updated_at' => now(),
            ]);

            $this->track($appId, (int) $invitation->id, (int) $invitation->event_id, $invitation->artist_id, $invitation->invited_user_id, 'email_sent', 'email');

            return true;
        } catch (\Throwable $exception) {
            DB::table('artist_invitations')->where('id', $invitation->id)->update([
                'email_status' => 'failed',
                'email_failed_at' => now(),
                'last_email_error' => Str::limit($exception->getMessage(), 2000, ''),
                'send_attempts' => DB::raw('send_attempts + 1'),
                'resend_available_at' => now()->addMinutes(5),
                'updated_at' => now(),
            ]);

            $this->track($appId, (int) $invitation->id, (int) $invitation->event_id, $invitation->artist_id, $invitation->invited_user_id, 'email_failed', 'email');
            Log::error('Falha ao enviar convite artístico por e-mail.', [
                'app_id' => $appId,
                'event_id' => $invitation->event_id,
                'invitation_id' => $invitation->id,
                'recipient_hash' => hash('sha256', mb_strtolower(trim($email))),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function invitationPayload(
        int $appId,
        object $invitation,
        Event $event,
        ?Artist $artist,
        ?object $pivot,
        User $user
    ): array {
        $producer = $event->production;
        $conflicts = [];

        if ($artist && $pivot?->scheduled_at) {
            $time = Carbon::parse($pivot->scheduled_at);
            $conflicts = DB::table('event_artist')
                ->join('events', 'events.id', '=', 'event_artist.event_id')
                ->where('event_artist.app_id', $appId)
                ->where('event_artist.artist_id', $artist->id)
                ->where('event_artist.event_id', '!=', $event->id)
                ->where('event_artist.status', 'confirmed')
                ->whereBetween('event_artist.scheduled_at', [$time->copy()->subHours(2), $time->copy()->addHours(2)])
                ->select(['events.id','events.title','events.slug','event_artist.scheduled_at','event_artist.stage'])
                ->limit(5)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $storedPayload = json_decode((string) ($invitation->payload ?? '{}'), true) ?: [];
        $materialChanges = is_array($storedPayload['_changed_fields'] ?? null)
            ? $storedPayload['_changed_fields']
            : [];

        return [
            'invitation' => [
                'id' => (int) $invitation->id,
                'token' => $invitation->token,
                'status' => $invitation->status,
                'email_status' => $invitation->email_status,
                'expires_at' => $invitation->expires_at,
                'responded_at' => $invitation->responded_at,
                'decline_reason' => $invitation->decline_reason,
                'viewed_at' => $invitation->viewed_at ?: now(),
                'important_change_count' => (int) ($invitation->important_change_count ?? 0),
                'last_material_change_at' => $invitation->last_material_change_at,
                'material_changes' => $materialChanges,
                'can_respond' => in_array($invitation->status, ['pending','pending_change'], true)
                    && ! $event->is_cancelled
                    && (! $event->start_date || Carbon::parse($event->start_date)->isFuture()),
            ],
            'event' => [
                'id' => (int) $event->id,
                'slug' => $event->slug,
                'title' => $event->title,
                'image' => $event->image,
                'start_date' => optional($event->start_date)->toIso8601String(),
                'end_date' => optional($event->end_date)->toIso8601String(),
                'venue' => $event->venue,
                'formatted_address' => $event->formatted_address ?: trim(implode(', ', array_filter([$event->address, $event->address_number, $event->neighborhood, $event->city, $event->uf]))),
                'google_maps_url' => $event->google_maps_url,
                'city' => $event->city,
                'uf' => $event->uf,
                'is_cancelled' => (bool) $event->is_cancelled,
                'contact_email' => $event->contact_email ?: $event->organizer_email,
                'contact_phone' => $event->contact_phone ?: $event->organizer_phone,
            ],
            'production' => [
                'id' => $producer?->id ? (int) $producer->id : null,
                'name' => $producer?->name,
                'slug' => $producer?->slug,
                'user_id' => $producer?->user_id ? (int) $producer->user_id : null,
            ],
            'artist' => $artist ? [
                'id' => (int) $artist->id,
                'slug' => $artist->slug,
                'stage_name' => $artist->stage_name,
                'photo' => $artist->photo,
            ] : null,
            'participation' => [
                'participation_type' => $pivot?->participation_type,
                'description' => $pivot?->description,
                'scheduled_at' => $pivot?->scheduled_at,
                'stage' => $pivot?->stage,
                'is_headliner' => (bool) ($pivot?->is_headliner ?? false),
                'fee_cents' => $pivot?->fee_cents,
                'payment_status' => $pivot?->payment_status,
            ],
            'schedule_conflicts' => $conflicts,
            'actions' => [
                'calendar_url' => '/api/v1/apps/'.rawurlencode((string) (Application::query()->find($appId)?->slug ?: $appId)).'/artist-invitations/'.$invitation->token.'/calendar.ics',
                'message_user_id' => $producer?->user_id ? (int) $producer->user_id : null,
            ],
            'responding_user_id' => (int) $user->id,
        ];
    }

    private function notifyProducerOfResponse(
        int $appId,
        Event $event,
        object $invitation,
        User $responder,
        string $status,
        ?string $declineReason
    ): void {
        $producerUserId = (int) ($event->production?->user_id ?? 0);
        if ($producerUserId <= 0) return;

        $name = $this->userName($responder);
        $confirmed = $status === 'confirmed';

        $this->notifications->sendToUser($appId, $producerUserId, [
            'type' => 'artist_event_response',
            'title' => $confirmed ? 'Artista confirmou presença' : 'Artista recusou o convite',
            'message' => $confirmed
                ? $name.' confirmou participação em '.$event->title.'.'
                : $name.' recusou o convite para '.$event->title.($declineReason ? ' Motivo: '.Str::limit($declineReason, 180) : '.'),
            'reference_type' => 'event',
            'reference_id' => $event->id,
            'reference_url' => '/event/'.$event->id.'/lineup',
            'data' => [
                'invitation_id' => $invitation->id,
                'artist_id' => $invitation->artist_id,
                'status' => $status,
                'decline_reason' => $declineReason,
            ],
        ]);

        $this->push->sendToUser($appId, $producerUserId, [
            'title' => $confirmed ? 'Artista confirmou' : 'Artista recusou',
            'body' => $name.' respondeu sobre '.$event->title.'.',
            'url' => '/event/'.$event->id.'/lineup',
            'tag' => 'artist-response-'.$invitation->id,
        ]);
    }

    private function ownedEvent(int $appId, int $eventId, User $user): Event
    {
        $event = Event::query()->where('app_id', $appId)->with('production')->findOrFail($eventId);
        abort_unless(
            $event->production && ($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id),
            403,
            'Você não pode gerenciar este evento.'
        );

        return $event;
    }

    private function authorizeResponder(int $appId, object $invitation, User $user): void
    {
        if ((int) $invitation->invited_user_id === (int) $user->id) return;

        if ($invitation->artist_id) {
            $artist = Artist::query()->where('app_id', $appId)->find($invitation->artist_id);
            if ($artist && (int) $artist->user_id === (int) $user->id) return;

            $manager = DB::table('artist_managers')
                ->where('app_id', $appId)
                ->where('artist_id', $invitation->artist_id)
                ->where('user_id', $user->id)
                ->exists();
            if ($manager) return;
        }

        abort(403, 'Este convite não pertence à sua conta nem a um artista que você administra.');
    }

    private function invitationByToken(int $appId, string $token): object
    {
        $invitation = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('token', $token)
            ->first();

        abort_unless($invitation, 404, 'Convite não encontrado.');

        return $invitation;
    }

    private function expireIfNeeded(object $invitation, Event $event): void
    {
        if (! in_array($invitation->status, self::ACTIVE_STATUSES, true)) return;
        if (! $this->isExpired($invitation, $event)) return;

        DB::table('artist_invitations')->where('id', $invitation->id)->update(['status' => 'expired', 'updated_at' => now()]);
        if ($invitation->artist_id) {
            DB::table('event_artist')
                ->where('app_id', $invitation->app_id)
                ->where('event_id', $event->id)
                ->where('artist_id', $invitation->artist_id)
                ->whereIn('status', ['pending','pending_change'])
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }
    }

    private function isExpired(object $invitation, Event $event): bool
    {
        if ($invitation->expires_at && Carbon::parse($invitation->expires_at)->lte(now())) return true;
        return (bool) ($event->start_date && Carbon::parse($event->start_date)->lte(now()));
    }

    private function expiresAt(Event $event): Carbon
    {
        $thirtyDays = now()->addDays(30);
        if (! $event->start_date) return $thirtyDays;

        $eventStart = Carbon::parse($event->start_date)->subMinute();
        return $eventStart->lt($thirtyDays) ? $eventStart : $thirtyDays;
    }

    private function participationPayload(array $data): array
    {
        return [
            'participation_type' => trim((string) ($data['participation_type'] ?? 'show')) ?: 'show',
            'description' => isset($data['description']) ? trim((string) $data['description']) ?: null : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'stage' => isset($data['stage']) ? trim((string) $data['stage']) ?: null : null,
            'is_headliner' => (bool) ($data['is_headliner'] ?? false),
            'fee_cents' => array_key_exists('fee_cents', $data) && $data['fee_cents'] !== null ? (int) $data['fee_cents'] : null,
            'private_notes' => isset($data['private_notes']) ? trim((string) $data['private_notes']) ?: null : null,
        ];
    }

    private function decryptRecipientEmail(object $invitation): ?string
    {
        if (! $invitation->recipient_email_encrypted) return null;
        try {
            $email = Crypt::decryptString((string) $invitation->recipient_email_encrypted);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function baseUrl(?Application $application): string
    {
        $base = rtrim(trim((string) ($application?->url ?? config('app.url'))), '/');
        return filter_var($base, FILTER_VALIDATE_URL) ? $base : rtrim((string) config('app.url'), '/');
    }

    private function userName(User $user): string
    {
        return trim(implode(' ', array_filter([$user->first_name, $user->last_name]))) ?: ($user->user_name ?: 'Responsável pelo evento');
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) return null;
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function normalizeComparable(mixed $value): string
    {
        if ($value === null || $value === '') return '';
        if (is_bool($value)) return $value ? '1' : '0';
        return trim((string) $value);
    }

    private function track(
        int $appId,
        int $invitationId,
        int $eventId,
        ?int $artistId,
        ?int $userId,
        string $eventType,
        string $source,
        ?array $metadata = null
    ): void {
        DB::table('artist_invitation_events')->insert([
            'app_id' => $appId,
            'invitation_id' => $invitationId,
            'event_id' => $eventId,
            'artist_id' => $artistId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'source' => $source,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function audit(
        int $appId,
        ?int $artistId,
        int $eventId,
        ?int $actorId,
        string $action,
        ?array $before,
        ?array $after
    ): void {
        DB::table('artist_audit_logs')->insert([
            'app_id' => $appId,
            'artist_id' => $artistId,
            'event_id' => $eventId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function averageResponseMinutes(int $appId, int $eventId): ?float
    {
        $rows = DB::table('artist_invitations')
            ->where('app_id', $appId)
            ->where('event_id', $eventId)
            ->whereNotNull('responded_at')
            ->get(['created_at','responded_at']);

        if ($rows->isEmpty()) return null;

        $minutes = $rows->map(fn ($row) => Carbon::parse($row->created_at)->diffInMinutes(Carbon::parse($row->responded_at)))->avg();
        return round((float) $minutes, 1);
    }

    private function icsEscape(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r", "\n"], ["\\\\", "\;", "\,", "", "\\n"], $value);
    }
}
