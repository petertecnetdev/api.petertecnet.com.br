<?php

namespace App\Services;

use App\Mail\InviteCompleteMail;
use App\Mail\InviteUserMail;
use App\Models\Application;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserInvitationService
{
    private const INVITATION_HOURS = 48;
    private const WHATSAPP_CODE_MINUTES = 10;
    private const MAX_VERIFICATION_ATTEMPTS = 6;

    public function __construct(private readonly WhatsAppOnboardingService $whatsapp)
    {
    }

    public function listRecent(int $limit = 10): array
    {
        return UserInvitation::query()
            ->with([
                'user:id,first_name,last_name,email,phone,phone_normalized,whatsapp_verified_at',
                'application:id,name,slug,url,is_active',
                'inviter:id,first_name,last_name,email',
            ])
            ->latest('id')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->map(fn (UserInvitation $invitation) => $this->serializeInvitation($invitation))
            ->values()
            ->all();
    }

    public function createProspect(array $data, ?int $actorId): array
    {
        $channel = $this->resolveChannel($data);
        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? strtolower(trim((string) $data['email']))
            : null;
        $phone = $this->whatsapp->normalizePhone($data['phone'] ?? null);
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $application = Application::query()->findOrFail((int) $data['application_id']);

        if (! $application->is_active) {
            return $this->result(422, ['message' => 'A plataforma selecionada está inativa.']);
        }

        if ($channel === 'email' && ! $email) {
            return $this->result(422, ['message' => 'Informe um e-mail válido para enviar este convite.']);
        }

        if ($channel === 'whatsapp') {
            if (! $phone) {
                return $this->result(422, ['message' => 'Informe um número de WhatsApp válido, com DDD.']);
            }

            if (! (bool) ($data['whatsapp_consent'] ?? false)) {
                return $this->result(422, [
                    'message' => 'Confirme que o titular forneceu este WhatsApp para contato e onboarding antes de enviar o convite.',
                ]);
            }

            if (! $this->whatsapp->isConfigured()) {
                return $this->result(503, [
                    'message' => 'O canal WhatsApp está implementado, mas a WhatsApp Cloud API ainda não está configurada no servidor.',
                    'code' => 'WHATSAPP_NOT_CONFIGURED',
                ]);
            }
        }

        $emailUser = $email
            ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
            : null;
        $phoneUser = $phone
            ? User::query()->where('phone_normalized', $phone)->first()
            : null;

        if ($emailUser && $phoneUser && $emailUser->id !== $phoneUser->id) {
            return $this->result(409, [
                'message' => 'O e-mail e o WhatsApp informados pertencem a contas diferentes. Revise os dados antes de continuar.',
            ]);
        }

        $user = $emailUser ?: $phoneUser;
        $isNewUser = ! $user;
        $destination = $channel === 'whatsapp' ? $phone : $email;

        if ($user && DB::table('application_user')
            ->where('user_id', $user->id)
            ->where('application_id', $application->id)
            ->where('status', 'active')
            ->exists()) {
            return $this->result(409, [
                'message' => 'Este usuário já possui acesso ativo à plataforma selecionada.',
            ]);
        }

        $duplicate = UserInvitation::query()
            ->where('application_id', $application->id)
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->where('status', 'pending')
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', now())
            ->first();

        if ($duplicate) {
            return $this->result(409, [
                'message' => 'Já existe um convite pendente para este contato e esta plataforma. Use “Reenviar”.',
                'invitation' => $this->serializeInvitation($duplicate),
            ]);
        }

        $rawToken = $this->newToken();
        $rawCode = $channel === 'whatsapp' ? $this->newNumericCode(6) : $this->newCode(8);
        $codeExpiresAt = $channel === 'whatsapp'
            ? now()->addMinutes(self::WHATSAPP_CODE_MINUTES)
            : now()->addHours(self::INVITATION_HOURS);

        $invitation = DB::transaction(function () use (
            $user,
            $isNewUser,
            $email,
            $phone,
            $destination,
            $channel,
            $recipientName,
            $application,
            $data,
            $rawToken,
            $rawCode,
            $codeExpiresAt,
            $actorId
        ) {
            if (! $user) {
                $fallback = $email ? Str::before($email, '@') : 'usuario';
                $name = $recipientName !== '' ? $recipientName : $fallback;

                $user = User::create([
                    'first_name' => $name !== '' ? $name : 'Usuário',
                    'email' => $email,
                    'phone' => $phone,
                    'phone_normalized' => $phone,
                    'password' => Hash::make(Str::random(64)),
                    'user_name' => $this->uniqueUsername($name !== '' ? $name : 'usuario'),
                ]);
            }

            $pivotMetadata = [
                'invited_by' => $actorId,
                'invited_at' => now()->toIso8601String(),
                'persona' => $data['persona'],
                'invitation_channel' => $channel,
            ];

            $user->applications()->syncWithoutDetaching([
                $application->id => [
                    'status' => 'pending',
                    'role' => $data['persona'],
                    'metadata' => json_encode($pivotMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'joined_at' => null,
                ],
            ]);

            return UserInvitation::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'invited_by' => $actorId,
                'channel' => $channel,
                'destination' => $destination,
                'token_hash' => hash('sha256', $rawToken),
                'verification_code_hash' => Hash::make($rawCode),
                'code_expires_at' => $codeExpiresAt,
                'verification_attempts' => 0,
                'delivery_status' => 'queued',
                'status' => 'pending',
                'expires_at' => now()->addHours(self::INVITATION_HOURS),
                'metadata' => [
                    'recipient_name' => $recipientName !== '' ? $recipientName : null,
                    'persona' => $data['persona'],
                    'email_snapshot' => $email,
                    'phone_snapshot' => $phone,
                    'requires_password' => $isNewUser,
                    'whatsapp_consent' => $channel === 'whatsapp' ? true : null,
                    'whatsapp_consent_attested_by' => $channel === 'whatsapp' ? $actorId : null,
                    'whatsapp_consent_attested_at' => $channel === 'whatsapp' ? now()->toIso8601String() : null,
                ],
            ])->load(['user', 'application', 'inviter']);
        });

        try {
            $delivery = $this->sendInvitation($invitation, $rawCode, $rawToken);
            $this->markDelivered($invitation, $delivery);
        } catch (\Throwable $exception) {
            $this->markDeliveryFailed($invitation, $exception);

            return $this->result(503, [
                'message' => $channel === 'whatsapp'
                    ? 'O convite foi criado, mas não foi possível enviá-lo pelo WhatsApp. Use “Reenviar” depois de revisar a integração.'
                    : 'O convite foi criado, mas o e-mail não pôde ser enviado. Use “Reenviar” para tentar novamente.',
                'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
            ]);
        }

        return $this->result(201, [
            'message' => $channel === 'whatsapp'
                ? 'Convite enviado pelo WhatsApp. O número será confirmado pelo código antes de liberar o acesso.'
                : 'Convite enviado com segurança. O acesso só será liberado após a confirmação do e-mail.',
            'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
        ]);
    }

    public function showByToken(string $token): array
    {
        $invitation = UserInvitation::query()
            ->with(['user:id,first_name,last_name,email,phone,phone_normalized', 'application:id,name,slug,url,is_active'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation) {
            return $this->result(404, ['message' => 'Convite inválido.']);
        }

        if ($this->effectiveStatus($invitation) !== 'pending') {
            $this->persistExpiration($invitation);
            return $this->result(410, ['message' => $this->unavailableMessage($invitation)]);
        }

        return $this->result(200, [
            'channel' => $invitation->channel ?: 'email',
            'email' => $invitation->user?->email ?: data_get($invitation->metadata, 'email_snapshot'),
            'phone' => $invitation->destination ?: $invitation->user?->phone_normalized ?: $invitation->user?->phone,
            'recipient_name' => data_get($invitation->metadata, 'recipient_name'),
            'persona' => data_get($invitation->metadata, 'persona'),
            'requires_password' => (bool) data_get($invitation->metadata, 'requires_password', true),
            'code_expires_at' => $invitation->code_expires_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'application' => $invitation->application?->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    public function activate(string $token, string $verificationCode, ?string $password): array
    {
        $result = DB::transaction(function () use ($token, $verificationCode, $password) {
            $invitation = UserInvitation::query()
                ->with(['user', 'application'])
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return $this->result(404, ['message' => 'Convite inválido.']);
            }

            if ($this->effectiveStatus($invitation) !== 'pending') {
                $this->persistExpiration($invitation);
                return $this->result(410, ['message' => $this->unavailableMessage($invitation)]);
            }

            if ($invitation->code_expires_at && now()->greaterThan($invitation->code_expires_at)) {
                return $this->result(422, ['message' => 'O código de confirmação expirou. Solicite o reenvio do convite.']);
            }

            if ((int) $invitation->verification_attempts >= self::MAX_VERIFICATION_ATTEMPTS) {
                return $this->result(429, ['message' => 'Limite de tentativas atingido. Solicite um novo código.']);
            }

            $normalizedCode = $invitation->channel === 'whatsapp'
                ? preg_replace('/\D+/', '', $verificationCode)
                : strtoupper(trim($verificationCode));

            if (! Hash::check((string) $normalizedCode, $invitation->verification_code_hash)) {
                $invitation->increment('verification_attempts');

                return $this->result(422, [
                    'message' => 'Código de verificação inválido.',
                    'attempts_remaining' => max(0, self::MAX_VERIFICATION_ATTEMPTS - ((int) $invitation->verification_attempts + 1)),
                ]);
            }

            $requiresPassword = (bool) data_get($invitation->metadata, 'requires_password', true);
            if ($requiresPassword && trim((string) $password) === '') {
                return $this->result(422, ['message' => 'Crie uma senha para concluir seu primeiro acesso.']);
            }

            $user = $invitation->user;
            $application = $invitation->application;

            if (! $user) {
                return $this->result(409, ['message' => 'O usuário deste convite não está mais disponível.']);
            }

            if (! $application || ! $application->is_active) {
                return $this->result(409, ['message' => 'A plataforma deste convite não está disponível no momento.']);
            }

            $updates = [
                'verification_code' => null,
                'verification_code_expires_at' => null,
            ];

            if ($requiresPassword) {
                $updates['password'] = Hash::make((string) $password);
            }

            if ($invitation->channel === 'whatsapp') {
                $phone = $this->whatsapp->normalizePhone($invitation->destination);
                if (! $phone) {
                    return $this->result(422, ['message' => 'O WhatsApp deste convite não é válido.']);
                }

                $otherUser = User::query()
                    ->where('phone_normalized', $phone)
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($otherUser) {
                    return $this->result(409, ['message' => 'Este WhatsApp já foi confirmado por outra conta.']);
                }

                $updates['phone'] = $phone;
                $updates['phone_normalized'] = $phone;
                $updates['whatsapp_verified_at'] = $user->whatsapp_verified_at ?: now();
            } else {
                $updates['email_verified_at'] = $user->email_verified_at ?: now();
                if (! $user->email && $invitation->destination) {
                    $updates['email'] = strtolower((string) $invitation->destination);
                }
            }

            $user->forceFill($updates)->save();

            $pivotMetadata = [
                'invited_by' => $invitation->invited_by,
                'invited_at' => $invitation->created_at?->toIso8601String(),
                'activated_at' => now()->toIso8601String(),
                'persona' => data_get($invitation->metadata, 'persona', 'client'),
                'invitation_channel' => $invitation->channel ?: 'email',
            ];

            $user->applications()->syncWithoutDetaching([
                $application->id => [
                    'status' => 'active',
                    'role' => data_get($invitation->metadata, 'persona', 'client'),
                    'metadata' => json_encode($pivotMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'joined_at' => now(),
                ],
            ]);

            $invitation->forceFill([
                'status' => 'accepted',
                'consumed_at' => now(),
                'verification_attempts' => 0,
            ])->save();

            return [
                'status' => 200,
                'body' => [
                    'message' => 'Conta ativada com sucesso.',
                    'channel' => $invitation->channel ?: 'email',
                    'application' => $application->only(['id', 'name', 'slug', 'url']),
                ],
                'activated_user' => $user,
                'activated_invitation' => $invitation,
            ];
        });

        if (($result['status'] ?? 500) !== 200 || ! isset($result['activated_user'], $result['activated_invitation'])) {
            return $result;
        }

        $user = $result['activated_user'];
        $invitation = $result['activated_invitation'];

        if (($invitation->channel ?: 'email') === 'whatsapp') {
            try {
                $this->whatsapp->sendActivationComplete($invitation);
            } catch (\Throwable $exception) {
                Log::warning('Conta ativada, mas a confirmação via WhatsApp falhou.', [
                    'user_id' => $user->id,
                    'invitation_id' => $invitation->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        } elseif ($user->email) {
            try {
                Mail::to($user->email)->send(new InviteCompleteMail($user));
            } catch (\Throwable $exception) {
                Log::warning('Conta ativada, mas o e-mail de confirmação falhou.', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        unset($result['activated_user'], $result['activated_invitation']);

        return $result;
    }

    public function resend(int $invitationId, ?int $actorId): array
    {
        $invitation = UserInvitation::query()
            ->with(['user', 'application', 'inviter'])
            ->find($invitationId);

        if (! $invitation) {
            return $this->result(404, ['message' => 'Convite não encontrado.']);
        }

        if ($invitation->status === 'accepted' || $invitation->consumed_at) {
            return $this->result(409, ['message' => 'Este convite já foi aceito e não pode ser reenviado.']);
        }

        if (! $invitation->application || ! $invitation->application->is_active) {
            return $this->result(409, ['message' => 'A plataforma deste convite está inativa.']);
        }

        if ($invitation->channel === 'whatsapp' && ! $this->whatsapp->isConfigured()) {
            return $this->result(503, [
                'message' => 'A WhatsApp Cloud API ainda não está configurada no servidor.',
                'code' => 'WHATSAPP_NOT_CONFIGURED',
            ]);
        }

        $rawToken = $this->newToken();
        $rawCode = $invitation->channel === 'whatsapp' ? $this->newNumericCode(6) : $this->newCode(8);
        $codeExpiresAt = $invitation->channel === 'whatsapp'
            ? now()->addMinutes(self::WHATSAPP_CODE_MINUTES)
            : now()->addHours(self::INVITATION_HOURS);

        DB::transaction(function () use ($invitation, $rawToken, $rawCode, $codeExpiresAt, $actorId) {
            $metadata = (array) ($invitation->metadata ?: []);
            $metadata['resent_at'] = now()->toIso8601String();
            $metadata['resent_by'] = $actorId;

            $invitation->forceFill([
                'token_hash' => hash('sha256', $rawToken),
                'verification_code_hash' => Hash::make($rawCode),
                'code_expires_at' => $codeExpiresAt,
                'verification_attempts' => 0,
                'status' => 'pending',
                'expires_at' => now()->addHours(self::INVITATION_HOURS),
                'consumed_at' => null,
                'revoked_at' => null,
                'last_sent_at' => null,
                'delivery_status' => 'queued',
                'provider_message_id' => null,
                'metadata' => $metadata,
            ])->save();

            $invitation->user?->applications()->syncWithoutDetaching([
                $invitation->application_id => [
                    'status' => 'pending',
                    'role' => data_get($metadata, 'persona', 'client'),
                    'joined_at' => null,
                ],
            ]);
        });

        try {
            $delivery = $this->sendInvitation($invitation, $rawCode, $rawToken);
            $this->markDelivered($invitation, $delivery);
        } catch (\Throwable $exception) {
            $this->markDeliveryFailed($invitation, $exception);

            return $this->result(503, [
                'message' => $invitation->channel === 'whatsapp'
                    ? 'Não foi possível reenviar o convite pelo WhatsApp.'
                    : 'Não foi possível reenviar o e-mail neste momento.',
            ]);
        }

        return $this->result(200, [
            'message' => 'Convite reenviado. O link e o código anteriores foram invalidados.',
            'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
        ]);
    }

    public function revoke(int $invitationId): array
    {
        $invitation = UserInvitation::query()->find($invitationId);

        if (! $invitation) {
            return $this->result(404, ['message' => 'Convite não encontrado.']);
        }

        if ($invitation->status === 'accepted' || $invitation->consumed_at) {
            return $this->result(409, ['message' => 'Um convite já aceito não pode ser revogado.']);
        }

        DB::transaction(function () use ($invitation) {
            $invitation->forceFill([
                'status' => 'revoked',
                'revoked_at' => now(),
            ])->save();

            DB::table('application_user')
                ->where('user_id', $invitation->user_id)
                ->where('application_id', $invitation->application_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);
        });

        return $this->result(200, [
            'message' => 'Convite revogado. O link não pode mais ser utilizado.',
            'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
        ]);
    }

    private function sendInvitation(UserInvitation $invitation, string $rawCode, string $rawToken): array
    {
        if (($invitation->channel ?: 'email') === 'whatsapp') {
            return $this->whatsapp->sendInvitation($invitation, $rawCode, $rawToken);
        }

        $this->sendInvitationMail($invitation, $rawCode, $rawToken);

        return ['provider_message_id' => null];
    }

    private function sendInvitationMail(UserInvitation $invitation, string $rawCode, string $rawToken): void
    {
        $invitation->loadMissing(['user', 'application']);

        if (! $invitation->user?->email) {
            throw new \RuntimeException('O usuário deste convite não possui e-mail.');
        }

        Mail::to($invitation->user->email)->send(new InviteUserMail(
            $invitation->user,
            $rawCode,
            $invitation->application->name,
            $invitation->application->url,
            $invitation->application->id,
            $rawToken,
        ));
    }

    private function markDelivered(UserInvitation $invitation, array $delivery): void
    {
        $messageId = data_get($delivery, 'authentication_message_id')
            ?: data_get($delivery, 'provider_message_id');

        $metadata = (array) ($invitation->metadata ?: []);
        if ($welcomeId = data_get($delivery, 'welcome_message_id')) {
            $metadata['welcome_provider_message_id'] = $welcomeId;
        }

        $invitation->forceFill([
            'last_sent_at' => now(),
            'delivery_status' => 'sent',
            'provider_message_id' => $messageId,
            'metadata' => $metadata,
        ])->save();
    }

    private function markDeliveryFailed(UserInvitation $invitation, \Throwable $exception): void
    {
        Log::error('Falha ao entregar convite de usuário.', [
            'invitation_id' => $invitation->id,
            'channel' => $invitation->channel ?: 'email',
            'message' => $exception->getMessage(),
        ]);

        $metadata = (array) ($invitation->metadata ?: []);
        $metadata['last_delivery_failure_at'] = now()->toIso8601String();

        $invitation->forceFill([
            'delivery_status' => 'failed',
            'metadata' => $metadata,
        ])->save();
    }

    private function serializeInvitation(UserInvitation $invitation): array
    {
        $invitation->loadMissing(['user', 'application', 'inviter']);

        return [
            'id' => $invitation->id,
            'channel' => $invitation->channel ?: 'email',
            'destination' => $invitation->destination,
            'email' => $invitation->user?->email ?: data_get($invitation->metadata, 'email_snapshot'),
            'phone' => $invitation->channel === 'whatsapp'
                ? ($invitation->destination ?: $invitation->user?->phone_normalized ?: $invitation->user?->phone)
                : ($invitation->user?->phone_normalized ?: $invitation->user?->phone),
            'recipient_name' => data_get($invitation->metadata, 'recipient_name'),
            'persona' => data_get($invitation->metadata, 'persona'),
            'status' => $this->effectiveStatus($invitation),
            'delivery_status' => $invitation->delivery_status,
            'code_expires_at' => $invitation->code_expires_at?->toIso8601String(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'last_sent_at' => $invitation->last_sent_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
            'application' => $invitation->application?->only(['id', 'name', 'slug', 'url']),
            'invited_by' => $invitation->inviter ? [
                'id' => $invitation->inviter->id,
                'name' => trim(($invitation->inviter->first_name ?? '').' '.($invitation->inviter->last_name ?? '')),
                'email' => $invitation->inviter->email,
            ] : null,
        ];
    }

    private function effectiveStatus(UserInvitation $invitation): string
    {
        if ($invitation->status === 'pending' && $invitation->expires_at && now()->greaterThan($invitation->expires_at)) {
            return 'expired';
        }

        return (string) $invitation->status;
    }

    private function persistExpiration(UserInvitation $invitation): void
    {
        if ($invitation->status === 'pending' && $invitation->expires_at && now()->greaterThan($invitation->expires_at)) {
            $invitation->forceFill(['status' => 'expired'])->save();
        }
    }

    private function unavailableMessage(UserInvitation $invitation): string
    {
        return match ($this->effectiveStatus($invitation)) {
            'accepted' => 'Este convite já foi utilizado.',
            'revoked' => 'Este convite foi revogado.',
            'expired' => 'Este convite expirou. Solicite um novo envio.',
            default => 'Este convite não está mais disponível.',
        };
    }

    private function resolveChannel(array $data): string
    {
        $channel = strtolower(trim((string) ($data['channel'] ?? '')));
        if (in_array($channel, ['email', 'whatsapp'], true)) {
            return $channel;
        }

        return ! empty($data['phone']) && empty($data['email']) ? 'whatsapp' : 'email';
    }

    private function newToken(): string
    {
        return Str::random(80);
    }

    private function newNumericCode(int $length): string
    {
        $min = 10 ** ($length - 1);
        $max = (10 ** $length) - 1;

        return (string) random_int($min, $max);
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($index = 0; $index < $length; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';

        do {
            $username = $base.'-'.Str::lower(Str::random(6));
        } while (User::query()->where('user_name', $username)->exists());

        return $username;
    }

    private function result(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
