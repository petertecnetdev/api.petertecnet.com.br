<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\ArtistIdentityService;
use App\Http\Controllers\Controller;
use App\Mail\ArtistEventInvitationMail;
use App\Models\AppNotification;
use App\Models\Application;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class ArtistInvitationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ArtistIdentityService $identity,
    ) {
    }

    public function resolve(Request $request, int $eventId)
    {
        $data = $request->validate([
            'identifier' => 'required|string|min:2|max:190',
            'participation_type' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'scheduled_at' => 'nullable|date',
            'stage' => 'nullable|string|max:160',
            'is_headliner' => 'nullable|boolean',
            'fee_cents' => 'nullable|integer|min:0|max:9999999999',
            'private_notes' => 'nullable|string|max:5000',
        ]);

        if ($this->identity->resolveUser($data['identifier'])) {
            return app(ArtistWorkflowController::class)->resolveAndInvite($request, $eventId);
        }

        $actor = $request->user();
        $event = $this->ownedEvent($eventId, $actor);
        $email = mb_strtolower(trim((string) $data['identifier']));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'Usuário não encontrado. Informe o e-mail da pessoa para enviarmos o convite de cadastro na Cutinapp.',
                'code' => 'ARTIST_INVITE_EMAIL_REQUIRED',
            ], 422);
        }

        return $this->sendExternalInvitation($event, $actor, $email, $data);
    }

    public function claimPending(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->email_verified_at, 403, 'Confirme seu e-mail antes de recuperar convites artísticos.');

        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '') {
            return response()->json(['claimed' => 0]);
        }

        $hash = hash('sha256', $email);
        $ids = DB::table('artist_invitations')
            ->where('app_id', $this->context->id())
            ->where('identifier_type', 'email')
            ->where('identifier_hash', $hash)
            ->where('status', 'pending_external')
            ->where('expires_at', '>=', now())
            ->orderBy('id')
            ->limit(20)
            ->pluck('id');

        $claimed = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $user, &$claimed): void {
                $invitation = DB::table('artist_invitations')->where('id', $id)->lockForUpdate()->first();
                if (! $invitation || $invitation->status !== 'pending_external') {
                    return;
                }

                $event = Event::query()
                    ->where('app_id', $this->context->id())
                    ->find($invitation->event_id);

                if (! $event) {
                    DB::table('artist_invitations')->where('id', $id)->update([
                        'status' => 'cancelled',
                        'updated_at' => now(),
                    ]);
                    return;
                }

                $actor = User::query()->find($invitation->invited_by_user_id) ?: $user;
                $artist = $this->identity->getOrCreate($this->context->id(), $user, $actor, $event, 'external_invitation');
                $payload = json_decode((string) ($invitation->payload ?? '{}'), true) ?: [];
                $existing = DB::table('event_artist')
                    ->where('event_id', $event->id)
                    ->where('artist_id', $artist->id)
                    ->first();
                $status = $existing?->status === 'confirmed' ? 'confirmed' : 'pending';
                $token = $existing?->invite_token ?: ($invitation->token ?: Str::random(48));

                $pivot = [
                    'app_id' => $this->context->id(),
                    'participation_type' => $payload['participation_type'] ?? 'show',
                    'description' => $payload['description'] ?? null,
                    'sort_order' => (int) ($payload['sort_order'] ?? 0),
                    'scheduled_at' => $payload['scheduled_at'] ?? null,
                    'stage' => $payload['stage'] ?? null,
                    'is_headliner' => (bool) ($payload['is_headliner'] ?? false),
                    'status' => $status,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                    'invited_at' => $existing?->invited_at ?: ($invitation->created_at ?: now()),
                    'fee_cents' => $payload['fee_cents'] ?? null,
                    'payment_status' => $existing?->payment_status ?: 'not_applicable',
                    'invite_token' => $token,
                    'private_notes' => $payload['private_notes'] ?? null,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    DB::table('event_artist')
                        ->where('event_id', $event->id)
                        ->where('artist_id', $artist->id)
                        ->update($pivot);
                } else {
                    DB::table('event_artist')->insert([
                        ...$pivot,
                        'event_id' => $event->id,
                        'artist_id' => $artist->id,
                        'created_at' => now(),
                    ]);
                }

                DB::table('artist_invitations')->where('id', $id)->update([
                    'artist_id' => $artist->id,
                    'invited_user_id' => $user->id,
                    'status' => $status === 'confirmed' ? 'accepted' : 'pending',
                    'updated_at' => now(),
                ]);

                if ($status !== 'confirmed') {
                    $producerLabel = $event->production?->name ?: 'A produção responsável';
                    AppNotification::query()->create([
                        'app_id' => $this->context->id(),
                        'user_id' => $user->id,
                        'type' => 'artist_event_invitation',
                        'title' => 'Seu convite artístico foi recuperado',
                        'message' => $producerLabel.' apresentou você para '.$event->title.'. Sua identidade artística pertence à sua conta; a produção fica apenas como referência de origem.',
                        'reference_type' => 'event',
                        'reference_id' => $event->id,
                        'reference_url' => '/artist/onboarding',
                        'data' => ['event_id' => $event->id, 'artist_id' => $artist->id, 'status' => 'pending', 'production_reference' => $producerLabel],
                    ]);
                }

                DB::table('artist_audit_logs')->insert([
                    'app_id' => $this->context->id(),
                    'artist_id' => $artist->id,
                    'event_id' => $event->id,
                    'actor_user_id' => $invitation->invited_by_user_id,
                    'action' => 'external_artist_invitation_claimed',
                    'before' => json_encode((array) $invitation),
                    'after' => json_encode($pivot),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $claimed++;
            }, 3);
        }

        return response()->json([
            'claimed' => $claimed,
            'message' => $claimed > 0
                ? 'Convites artísticos vinculados à sua conta.'
                : 'Nenhum convite artístico pendente encontrado.',
        ]);
    }

    private function sendExternalInvitation(Event $event, User $actor, string $email, array $data)
    {
        $identifierHash = hash('sha256', $email);
        $existing = DB::table('artist_invitations')
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('identifier_type', 'email')
            ->where('identifier_hash', $identifierHash)
            ->where('status', 'pending_external')
            ->where('expires_at', '>=', now())
            ->latest('id')
            ->first();

        $token = $existing?->token ?: Str::random(48);
        $payload = json_encode(collect($data)->except('identifier')->all());

        if ($existing) {
            DB::table('artist_invitations')->where('id', $existing->id)->update([
                'invited_by_user_id' => $actor->id,
                'identifier_hint' => $this->maskEmail($email),
                'token' => $token,
                'expires_at' => now()->addDays(30),
                'payload' => $payload,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('artist_invitations')->insert([
                'app_id' => $this->context->id(),
                'event_id' => $event->id,
                'artist_id' => null,
                'invited_user_id' => null,
                'invited_by_user_id' => $actor->id,
                'identifier_type' => 'email',
                'identifier_hash' => $identifierHash,
                'identifier_hint' => $this->maskEmail($email),
                'status' => 'pending_external',
                'token' => $token,
                'expires_at' => now()->addDays(30),
                'payload' => $payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $application = Application::query()->find($this->context->id());
        $baseUrl = rtrim(trim((string) ($application?->url ?? '')), '/');
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $baseUrl = 'https://cutinapp.petertecnet.com.br';
        }

        $registrationUrl = $baseUrl.'/register?'.http_build_query(['artist_invite' => $token]);
        $producerName = trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: 'Uma produção';

        try {
            Mail::to($email)->send(new ArtistEventInvitationMail(
                $event->title,
                $producerName,
                $registrationUrl,
                $data['participation_type'] ?? null,
                $data['scheduled_at'] ?? null,
                $data['stage'] ?? null,
            ));
        } catch (\Throwable $exception) {
            Log::error('Falha ao enviar convite artístico externo.', [
                'event_id' => $event->id,
                'app_id' => $this->context->id(),
                'email_hash' => $identifierHash,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'O convite foi criado, mas o e-mail não pôde ser enviado agora. Tente novamente em alguns instantes.',
                'external_invitation' => true,
                'invitation_sent' => false,
            ], 503);
        }

        return response()->json([
            'message' => 'Usuário ainda não possui conta. Convite de cadastro enviado por e-mail.',
            'external_invitation' => true,
            'invitation_sent' => true,
        ], 202);
    }

    private function ownedEvent(int $id, User $user): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->with('production')
            ->findOrFail($id);

        abort_unless(
            $event->production && ($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id),
            403,
            'Você não pode gerenciar este evento.'
        );

        return $event;
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
