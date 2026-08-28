<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['token_id' => 'required|string']);
        $clientId = trim((string) config('services.google.client_id'));

        if ($clientId === '') {
            Log::error('Google OAuth não configurado.');
            return response()->json(['message' => 'Login com Google indisponível.'], 503);
        }

        try {
            $client = new \Google_Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($data['token_id']);

            if (! is_array($payload)
                || empty($payload['sub'])
                || empty($payload['email'])
                || ! ($payload['email_verified'] ?? false)) {
                return response()->json(['message' => 'Credencial do Google inválida.'], 401);
            }

            $googleId = (string) $payload['sub'];
            $email = strtolower(trim((string) $payload['email']));
            $user = User::where('google_id', $googleId)->orWhere('email', $email)->first();

            if ($user && $user->google_id && $user->google_id !== $googleId) {
                return response()->json(['message' => 'Esta conta já está vinculada a outro login Google.'], 409);
            }

            if (! $user) {
                $firstName = trim((string) ($payload['given_name'] ?? 'Usuário')) ?: 'Usuário';
                $base = Str::slug($firstName) ?: 'usuario';
                do {
                    $username = $base . '-' . strtolower(Str::random(6));
                } while (User::where('user_name', $username)->exists());

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => trim((string) ($payload['family_name'] ?? '')) ?: null,
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'user_name' => $username,
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'avatar' => $payload['picture'] ?? null,
                ]);
            } else {
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?: now(),
                    'avatar' => $user->avatar ?: ($payload['picture'] ?? null),
                ])->save();
            }

            $token = auth()->login($user);
            Interaction::create([
                'user_id' => $user->id,
                'interaction_type' => 'login_google',
                'entity_id' => $user->id,
                'entity_type' => 'User',
            ]);

            return response()->json([
                'message' => 'Login com Google realizado com sucesso.',
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
                'user' => $user->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha no login com Google.', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'Não foi possível concluir o login com Google.'], 500);
        }
    }
}
