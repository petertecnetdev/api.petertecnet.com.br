<?php

namespace App\Http\Controllers;

use App\Mail\InviteCompleteMail;
use App\Models\Application;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationActivationController extends Controller
{
    public function show(string $token, InvitationService $invitations): JsonResponse
    {
        $invitation = $invitations->findUsableByToken($token);

        if (! $invitation) {
            return response()->json([
                'message' => 'Este convite é inválido, já foi utilizado ou expirou. Solicite um novo convite.',
            ], 410);
        }

        return response()->json([
            'email' => $invitation->user->email,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'application' => $invitation->application->only(['id', 'name', 'slug']),
        ]);
    }

    public function activate(Request $request, string $token, InvitationService $invitations): JsonResponse
    {
        $data = $request->validate([
            'verification_code' => ['required', 'string', 'min:4', 'max:12'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ], [
            'verification_code.required' => 'Digite o código de verificação recebido por e-mail.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $candidate = $invitations->findUsableByToken($token);
        if (! $candidate) {
            return response()->json([
                'message' => 'Este convite é inválido, já foi utilizado ou expirou. Solicite um novo convite.',
            ], 410);
        }

        [$user, $application] = DB::transaction(function () use ($candidate, $data, $invitations) {
            $invitation = UserInvitation::query()
                ->with(['user', 'application'])
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable()) {
                throw ValidationException::withMessages([
                    'token' => ['Este convite já foi utilizado, revogado ou expirou.'],
                ]);
            }

            if (! $invitations->codeMatches($invitation, $data['verification_code'])) {
                throw ValidationException::withMessages([
                    'verification_code' => ['Código de verificação inválido.'],
                ]);
            }

            $user = $invitation->user;
            $application = $invitation->application;
            $access = $user->applications()->whereKey($application->id)->first();

            if (! $access) {
                throw ValidationException::withMessages([
                    'application' => ['Este convite não possui um acesso válido ao aplicativo.'],
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'verification_code' => null,
                'verification_code_expires_at' => null,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();

            $user->applications()->updateExistingPivot($application->id, [
                'status' => 'active',
                'joined_at' => $access->pivot?->joined_at ?: now(),
            ]);

            $invitation->forceFill([
                'status' => 'consumed',
                'consumed_at' => now(),
            ])->save();

            return [$user, $application->fresh()];
        });

        $this->sendCompletionMail($user, $application);

        return response()->json([
            'message' => 'E-mail validado, senha criada e acesso ativado com sucesso.',
            'application' => $application->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    /**
     * Compatibilidade temporária com convites antigos que ainda usam e-mail + app_id.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'verification_code' => ['required', 'string', 'min:4', 'max:12'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'app_id' => ['required', 'integer', 'exists:applications,id'],
        ], [
            'app_id.required' => 'Não foi possível identificar o aplicativo deste convite.',
            'app_id.exists' => 'O aplicativo deste convite não está mais disponível.',
        ]);

        $email = strtolower(trim($data['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $application = Application::query()->findOrFail($data['app_id']);

        if (! $user || ! $this->legacyVerificationCodeIsValid($user, $data['verification_code'])) {
            return response()->json(['message' => 'Código de verificação inválido ou expirado.'], 422);
        }

        $access = $user->applications()->whereKey($application->id)->first();
        if (! $access) {
            return response()->json(['message' => 'Este convite não pertence ao aplicativo informado.'], 422);
        }

        DB::transaction(function () use ($user, $application, $data, $access) {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'verification_code' => null,
                'verification_code_expires_at' => null,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();

            $user->applications()->updateExistingPivot($application->id, [
                'status' => 'active',
                'joined_at' => $access->pivot?->joined_at ?: now(),
            ]);
        });

        $this->sendCompletionMail($user, $application);

        return response()->json([
            'message' => 'E-mail validado e acesso ativado com sucesso.',
            'application' => $application->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    private function legacyVerificationCodeIsValid(User $user, string $code): bool
    {
        if (! $user->verification_code || ! $user->verification_code_expires_at) {
            return false;
        }

        if (now()->greaterThan($user->verification_code_expires_at)) {
            return false;
        }

        return Hash::check(trim($code), $user->verification_code);
    }

    private function sendCompletionMail(User $user, Application $application): void
    {
        try {
            Mail::to($user->email)->queue(new InviteCompleteMail($user));
        } catch (\Throwable $e) {
            Log::warning('Ativação concluída, mas o e-mail de confirmação falhou.', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
