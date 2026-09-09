<?php

namespace App\Http\Controllers;

use App\Mail\InviteCompleteMail;
use App\Mail\InviteUserMail;
use App\Models\Application;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserInvitationController extends Controller
{
    private const INVITATION_HOURS = 48;

    public function index(Request $request)
    {
        $limit = max(1, min((int) $request->input('limit', 10), 50));

        $invitations = UserInvitation::query()
            ->with([
                'user:id,first_name,last_name,email',
                'application:id,name,slug,url,is_active',
                'inviter:id,first_name,last_name,email',
            ])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (UserInvitation $invitation) => $this->serializeInvitation($invitation));

        return response()->json(['data' => $invitations]);
    }

    public function storeProspect(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'persona' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        $email = strtolower(trim($data['email']));
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $application = Application::query()->findOrFail((int) $data['application_id']);

        if (! $application->is_active) {
            return response()->json(['message' => 'A plataforma selecionada está inativa.'], 422);
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
                'message' => 'Este e-mail já possui uma conta Peter Tecnet. Gerencie o acesso do usuário existente em vez de criar outro convite de primeiro acesso.',
            ], 409);
        }

        $rawToken = $this->newToken();
        $rawCode = $this->newCode(8);
        $actor = $request->user('api');

        $invitation = DB::transaction(function () use (
            $email,
            $recipientName,
            $application,
            $data,
            $rawToken,
            $rawCode,
            $actor
        ) {
            $name = $recipientName !== '' ? $recipientName : Str::before($email, '@');

            $user = User::create([
                'first_name' => $name !== '' ? $name : 'Usuário',
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'user_name' => $this->uniqueUsername($name !== '' ? $name : 'usuario'),
            ]);

            $pivotMetadata = [
                'invited_by' => $actor?->id,
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
                'invited_by' => $actor?->id,
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
            Mail::to($invitation->user->email)->send(new InviteUserMail(
                $invitation->user,
                $rawCode,
                $application->name,
                $application->url,
                $application->id,
                $rawToken,
            ));
        } catch (\Throwable $exception) {
            Log::error('Convite criado, mas o e-mail não pôde ser enviado.', [
                'invitation_id' => $invitation->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'O convite foi criado, mas o e-mail não pôde ser enviado. Use “Reenviar” para tentar novamente.',
                'invitation' => $this->serializeInvitation($invitation),
            ], 503);
        }

        return response()->json([
            'message' => 'Convite enviado com segurança. O acesso só será liberado após a confirmação do e-mail e criação da senha.',
            'invitation' => $this->serializeInvitation($invitation),
        ], 201);
    }

    public function show(string $token)
    {
        $invitation = UserInvitation::query()
            ->with(['user:id,first_name,last_name,email', 'application:id,name,slug,url,is_active'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation) {
            return response()->json(['message' => 'Convite inválido.'], 404);
        }

        if ($this->effectiveStatus($invitation) !== 'pending') {
            $this->persistExpiration($invitation);

            return response()->json([
                'message' => $this->unavailableMessage($invitation),
            ], 410);
        }

        return response()->json([
            'email' => $invitation->user->email,
            'recipient_name' => data_get($invitation->metadata, 'recipient_name'),
            'persona' => data_get($invitation->metadata, 'persona'),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'application' => $invitation->application?->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    public function activate(Request $request, string $token)
    {
        $data = $request->validate([
            'verification_code' => ['required', 'string', 'min:6', 'max:12'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $result = DB::transaction(function () use ($token, $data) {
            $invitation = UserInvitation::query()
                ->with(['user', 'application'])
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return ['error' => 'Convite inválido.', 'status' => 404];
            }

            if ($this->effectiveStatus($invitation) !== 'pending') {
                $this->persistExpiration($invitation);
                return ['error' => $this->unavailableMessage($invitation), 'status' => 410];
            }

            if (! Hash::check(strtoupper(trim($data['verification_code'])), $invitation->verification_code_hash)) {
                return ['error' => 'Código de verificação inválido.', 'status' => 422];
            }

            $user = $invitation->user;
            $application = $invitation->application;

            if (! $application || ! $application->is_active) {
                return ['error' => 'A plataforma deste convite não está disponível no momento.', 'status' => 409];
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
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

            return ['user' => $user, 'application' => $application];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        try {
            Mail::to($result['user']->email)->send(new InviteCompleteMail($result['user']));
        } catch (\Throwable $exception) {
            Log::warning('Conta ativada, mas o e-mail de confirmação falhou.', [
                'user_id' => $result['user']->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Conta ativada com sucesso.',
            'application' => $result['application']->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    public function resend(Request $request, UserInvitation $invitation)
    {
        $invitation->load(['user', 'application']);

        if ($invitation->status === 'accepted' || $invitation->consumed_at) {
            return response()->json(['message' => 'Este convite já foi aceito e não pode ser reenviado.'], 409);
        }

        if (! $invitation->application || ! $invitation->application->is_active) {
            return response()->json(['message' => 'A plataforma deste convite está inativa.'], 409);
        }

        $rawToken = $this->newToken();
        $rawCode = $this->newCode(8);

        DB::transaction(function () use ($invitation, $rawToken, $rawCode, $request) {
            $metadata = (array) ($invitation->metadata ?: []);
            $metadata['resent_at'] = now()->toIso8601String();
            $metadata['resent_by'] = $request->user('api')?->id;

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
            Mail::to($invitation->user->email)->send(new InviteUserMail(
                $invitation->user,
                $rawCode,
                $invitation->application->name,
                $invitation->application->url,
                $invitation->application->id,
                $rawToken,
            ));
        } catch (\Throwable $exception) {
            Log::error('Falha ao reenviar convite.', [
                'invitation_id' => $invitation->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Não foi possível reenviar o e-mail neste momento.'], 503);
        }

        return response()->json([
            'message' => 'Convite reenviado. O link e o código anteriores foram invalidados.',
            'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
        ]);
    }

    public function revoke(UserInvitation $invitation)
    {
        if ($invitation->status === 'accepted' || $invitation->consumed_at) {
            return response()->json(['message' => 'Um convite já aceito não pode ser revogado.'], 409);
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

        return response()->json([
            'message' => 'Convite revogado. O link não pode mais ser utilizado.',
            'invitation' => $this->serializeInvitation($invitation->fresh(['user', 'application', 'inviter'])),
        ]);
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
}
