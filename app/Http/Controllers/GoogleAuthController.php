<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class GoogleAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token_id' => 'required|string',
        ], [
            'token_id.required' => 'A credencial do Google não foi recebida.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Não foi possível autenticar com o Google.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $clientId = trim((string) config('services.google.client_id'));
        if ($clientId === '') {
            Log::error('GoogleAuth: GOOGLE_CLIENT_ID não configurado.');
            return response()->json([
                'message' => 'Login com Google não configurado no servidor.',
                'error' => 'GOOGLE_CLIENT_ID ausente na API.',
            ], 500);
        }

        try {
            $client = new \Google_Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($validator->validated()['token_id']);

            if (!$payload) {
                return response()->json([
                    'message' => 'O Google não conseguiu validar esta sessão. Tente novamente.',
                    'error' => 'Credencial do Google inválida ou destinada a outro Client ID.',
                ], 401);
            }

            $googleId = (string) ($payload['sub'] ?? '');
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $emailVerified = (bool) ($payload['email_verified'] ?? false);

            if ($googleId === '' || $email === '') {
                return response()->json([
                    'message' => 'A conta Google não retornou os dados necessários para o login.',
                ], 422);
            }

            if (!$emailVerified) {
                return response()->json([
                    'message' => 'O e-mail desta conta Google ainda não está verificado.',
                ], 422);
            }

            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                $user = User::where('email', $email)->first();
            }

            if (!$user) {
                $firstName = trim((string) ($payload['given_name'] ?? ''));
                if ($firstName === '') {
                    $firstName = trim(explode(' ', (string) ($payload['name'] ?? 'Usuário'))[0] ?: 'Usuário');
                }

                $usernameBase = Str::slug($firstName) ?: 'usuario';
                $username = $usernameBase . '-' . Str::lower(Str::random(4));
                while (User::where('user_name', $username)->exists()) {
                    $username = $usernameBase . '-' . Str::lower(Str::random(4));
                }

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => trim((string) ($payload['family_name'] ?? '')) ?: null,
                    'email' => $email,
                    'password' => bcrypt(Str::random(40)),
                    'user_name' => $username,
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'avatar' => $payload['picture'] ?? null,
                ]);
            } else {
                $dirty = false;
                if (!$user->google_id) {
                    $user->google_id = $googleId;
                    $dirty = true;
                }
                if (!$user->email_verified_at) {
                    $user->email_verified_at = now();
                    $dirty = true;
                }
                if (!$user->avatar && !empty($payload['picture'])) {
                    $user->avatar = $payload['picture'];
                    $dirty = true;
                }
                if ($dirty) {
                    $user->save();
                }
            }

            $token = auth()->login($user);

            try {
                Interaction::create([
                    'user_id' => $user->id,
                    'interaction_type' => 'login_google',
                    'entity_id' => $user->id,
                    'entity_type' => 'user',
                ]);
            } catch (\Throwable $interactionError) {
                Log::warning('GoogleAuth: falha ao registrar interação.', [
                    'user_id' => $user->id,
                    'error' => $interactionError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Login com Google realizado com sucesso.',
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
                'user' => $user->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('GoogleAuth: erro inesperado.', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'message' => 'Não foi possível concluir o login com Google.',
                'error' => config('app.debug') ? $e->getMessage() : 'Falha interna na autenticação Google.',
            ], 500);
        }
    }
}
