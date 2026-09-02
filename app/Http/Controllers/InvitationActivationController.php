<?php

namespace App\Http\Controllers;

use App\Mail\InviteCompleteMail;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class InvitationActivationController extends Controller
{
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

        if (! $user || ! $this->verificationCodeIsValid($user, $data['verification_code'])) {
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

            if ($access->pivot?->status !== 'active') {
                $user->applications()->updateExistingPivot($application->id, [
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }
        });

        try {
            Mail::to($user->email)->send(new InviteCompleteMail($user));
        } catch (\Throwable $e) {
            Log::warning('Ativação concluída, mas o e-mail de confirmação falhou.', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'E-mail validado e acesso ativado com sucesso.',
            'application' => $application->only(['id', 'name', 'slug', 'url']),
        ]);
    }

    private function verificationCodeIsValid(User $user, string $code): bool
    {
        if (! $user->verification_code || ! $user->verification_code_expires_at) {
            return false;
        }

        if (now()->greaterThan($user->verification_code_expires_at)) {
            return false;
        }

        return Hash::check(trim($code), $user->verification_code);
    }
}
