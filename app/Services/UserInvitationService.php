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

    public function listRecent(int $limit = 10): array
    {
        return UserInvitation::query()
            ->with([
                'user:id,first_name,last_name,email',
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
        $email = strtolower(trim((string) $data['email']));
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $application = Application::query()->findOrFail((int) $data['application_id']);

        if (! $application->is_active) {
            return $this->result(422, ['message' => 'A plataforma selecionada está inativa.']);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return $this->result(409, [
                'message' => 'Este e-mail já possui uma conta Peter Tecnet. Gerencie o acesso do usuário existente em vez de criar outro convite de primeiro acesso.',
            ]);
        }

        $rawToken = $this->newToken();
        $rawCode = $this->newCode(8);

        $invitation = DB::transaction(function () use (
            $email,
            $recipientName,
            $application,
            $data,
            $rawToken,
            $rawCode,
            $actorId
        ) {
            $name = $recipientName !== '' ? $recipientName : Str::before($email, '@');

            $user = User::create([
                'first_name' => $name !== '' ? $name : 'Usuário',
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'user_name' => $this->uniqueUsername($name !== '' ? $name : 'usuario'),
            ]);

            $pivotMetadata = [
                'invited_by' => $actorId,
                'invited_at' => now()->toIso8601String(),
                'persona' => $data['persona'],
            ];

            $user->applications()->attach($application->id, [
                'status' => 'pending',
                'role' => $data['persona'],
                'metadata' => json_encode($pivotMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'joined_at' => null,
            ]);

            return UserInvitation::create([
                'user_id' => $user->id,
                'application_id' => $application->id,
                'invited_by' => $actorId,
                'token_hash' => hash('sha256', $rawToken),
                'verification_code_hash' => Hash::make($rawCode),
                'status' => 'pending',
                'expires_at' => now()->addHours(self::INVITATION_HOURS),
                'metadata' => [
                    'recipient_name' => $recipientName !== '' ? $recipientName : null,
                    'persona' => $data['persona'],
                    'email_snapshot' => $email,
                ],
            ])->load(['user', 'application', 'inviter']);
        });

        try {
            $this->sendInvitationMail($invitation, $rawCode, $rawToken);
        } catch (\Throwable $exception) {
            Log::error('Convite criado, mas o e-mail não pôde ser enviado.', [
                'invitation_id' => $invitation->id,
                'message' => $exception->getMessage(),
            ]);

            return $this->result(503, [
                'message' => 'O convite foi criado, mas o e-mail não pôde ser enviado. Use “Reenviar” para tentar novamente.',
                'invitation' => $this->serializeInvitation($invitation),
            ]);
        }

        return $this->result(201, [
            'message' => 'Convite enviado com segurança. O acesso só será liberado após a confirmação do e-mail e criação da senha.',
            'invitation' => $this->serializeInvitation($invitation),
        ]);
    }

    public function showByToken(string $token): array
    {
        $invitation = UserInvitation::query()
            ->with(['user:id,first_name,last_name,email', 'application:id,name,slug,url,is_active'])
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
            'email' => $invitation->user->email,
            'recipient_name' => data_get($invitation->metadata, 'recipient_name'),
            'persona' => data_get($invitation->metadata, 'persona'),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'application' => $invitation->application?->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    public function activate(string $token, string $verificationCode, string $password): array
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

            if (! Hash::check(strtoupper(trim($verificationCode)), $invitation->verification_code_hash)) {
                return $this->result(422, ['message' => 'Código de verificação inválido.']);
            }

            $user = $invitation->user;
            $application = $invitation->application;

            if (! $application || ! $application->is_active) {
                return $this->result(409, ['message' => 'A plataforma deste convite não está disponível no momento.']);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => $user->email_verified_at ?: now(),
                'verification_code' => null,
                'verification_code_expires_at' => null,
            ])->save();

            $pivotMetadata = [
                'invited_by' => $invitation->invited_by,
                'invited_at' => $invitation->created_at?->toIso8601String(),
                'activated_at' => now()->toIso8601String(),
                'persona' => data_get($invitation->metadata, 'persona', 'client'),
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
            ])->save();

            return [
                'status' => 200,
                'body' => [
                    'message' => 'Conta ativada com sucesso.',
                    'application' => $application->only(['id', 'name', 'slug', 'url']),
                ],
                'activated_user' => $user,
            ];
        });

        if (($result['status'] ?? 500) !== 200 || ! isset($result['activated_user'])) {
            return $result;
        }

        try {
            Mail::to($result['activated_user']->email)->send(new InviteCompleteMail($result['activated_user']));
        } catch (\Throwable $exception) {
            Log::warning('Conta ativada, mas o e-mail de confirmação falhou.', [
                'user_id' => $result['activated_user']->id,
                'message' => $exception->getMessage(),
            ]);
        }

        unset($result['activated_user']);

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

        $rawToken = $this->newToken();
        $rawCode = $this->newCode(8);

        DB::transaction(function () use ($invitation, $rawToken, $rawCode, $actorId) {
            $metadata = (array) ($invitation->metadata ?: []);
            $metadata['resent_at'] = now()->toIso8601String();
            $metadata['resent_by'] = $actorId;

            $invitation->forceFill([
                'token_hash' => hash('sha256', $rawToken),
                'verification_code_hash' => Hash::make($rawCode),
                'status' => 'pending',
                'expires_at' => now()->addHours(self::INVITATION_HOURS),
                'consumed_at' => null,
                'revoked_at' => null,
                'metadata' => $metadata,
            ])->save();

            $invitation->user->applications()->syncWithoutDetaching([
                $invitation->application_id => [
                    'status' => 'pending',
                    'role' => data_get($metadata, 'persona', 'client'),
                    'joined_at' => null,
                ],
            ]);
        });

        try {
            $this->sendInvitationMail($invitation, $rawCode, $rawToken);
        } catch (\Throwable $exception) {
            Log::error('Falha ao reenviar convite.', [
                'invitation_id' => $invitation->id,
                'message' => $exception->getMessage(),
            ]);

            return $this->result(503, ['message' => 'Não foi possível reenviar o e-mail neste momento.']);
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

    private function sendInvitationMail(UserInvitation $invitation, string $rawCode, string $rawToken): void
    {
        $invitation->loadMissing(['user', 'application']);

        Mail::to($invitation->user->email)->send(new InviteUserMail(
            $invitation->user,
            $rawCode,
            $invitation->application->name,
            $invitation->application->url,
            $invitation->application->id,
            $rawToken,
        ));
    }

    private function serializeInvitation(UserInvitation $invitation): array
    {
        $invitation->loadMissing(['user', 'application', 'inviter']);

        return [
            'id' => $invitation->id,
            'email' => $invitation->user?->email ?: data_get($invitation->metadata, 'email_snapshot'),
            'recipient_name' => data_get($invitation->metadata, 'recipient_name'),
            'persona' => data_get($invitation->metadata, 'persona'),
            'status' => $this->effectiveStatus($invitation),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
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

    private function newToken(): string
    {
        return Str::random(80);
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
