<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoogleAuthRequest;
use App\Mail\InviteCompleteMail;
use App\Mail\InviteUserMail;
use App\Mail\ResendVerificationCodeMail;
use App\Mail\ResetPasswordMail;
use App\Mail\VerificationCodeMail;
use App\Models\Application;
use App\Models\Interaction;
use App\Models\User;
use Google_Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api', [
            'except' => [
                'login', 'register', 'sendResetCodeEmail', 'resetPassword',
                'googleAuth', 'completeInvite', 'refresh',
            ],
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $token = auth()->attempt(User::credentials($data['username'], $data['password']));

        if (! $token) {
            return response()->json(['error' => 'Credenciais inválidas.'], 401);
        }

        $user = auth()->user();
        $location = $this->resolveLocation(
            $request,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null
        );

        $user->updateAddress($location['city'], $location['uf']);
        $this->recordInteraction($user, 'login', [
            'ip' => $request->ip(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'city' => $location['city'],
            'uf' => $location['uf'],
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login realizado com sucesso!',
            'token' => $this->createNewToken($token),
        ]);
    }

    public function googleAuth(GoogleAuthRequest $request)
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            return response()->json(['error' => 'Login com Google indisponível.'], 503);
        }

        try {
            $payload = (new Google_Client(['client_id' => $clientId]))
                ->verifyIdToken($request->input('token_id'));

            if (! is_array($payload)
                || empty($payload['sub'])
                || empty($payload['email'])
                || ! ($payload['email_verified'] ?? false)) {
                return response()->json(['error' => 'Token do Google inválido.'], 401);
            }

            $googleId = (string) $payload['sub'];
            $email = strtolower(trim((string) $payload['email']));
            $firstName = trim((string) ($payload['given_name'] ?? 'Usuário')) ?: 'Usuário';

            $user = User::query()
                ->where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            if ($user && $user->google_id && $user->google_id !== $googleId) {
                return response()->json(['error' => 'Esta conta já está vinculada a outro login Google.'], 409);
            }

            if (! $user) {
                $user = User::create([
                    'first_name' => $firstName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'user_name' => $this->uniqueUsername($firstName),
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ]);
            } else {
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?: now(),
                ])->save();
            }

            $token = auth()->login($user);
            $this->recordInteraction($user, 'login_google');

            return response()->json([
                'message' => 'Login com Google realizado com sucesso!',
                'token' => $this->createNewToken($token),
            ]);
        } catch (\Throwable $e) {
            Log::error('Falha no login com Google.', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro durante o login com Google.'], 500);
        }
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-ZÀ-ÿ\\s]+$/'],
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'cpf' => ['nullable', 'regex:/^\\d{11}$/', 'unique:users,cpf'],
            'app_id' => 'nullable|integer|exists:applications,id',
        ]);

        $rawCode = $this->newCode(6);

        $user = DB::transaction(function () use ($data, $rawCode, $actor) {
            $user = User::create([
                'first_name' => trim($data['first_name']),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make($data['password']),
                'user_name' => $this->uniqueUsername($data['first_name']),
                'verification_code' => Hash::make($rawCode),
                'verification_code_expires_at' => now()->addMinutes(30),
                'cpf' => $data['cpf'] ?? null,
            ]);

            if (! empty($data['app_id'])) {
                $user->applications()->syncWithoutDetaching([
                    $data['app_id'] => ['status' => 'active', 'joined_at' => now()],
                ]);
            }

            return $user;
        });

        Mail::to($user->email)->send(new VerificationCodeMail($rawCode, $user));
        $this->recordInteraction($user, 'register');

        return response()->json(['message' => 'Registro bem-sucedido'], 201);
    }

    public function emailVerify(Request $request)
    {
        $data = $request->validate(['verification_code' => 'required|string|min:4|max:12']);
        $user = Auth::user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'O e-mail já foi verificado anteriormente.']);
        }

        if (! $this->verificationCodeIsValid($user, $data['verification_code'])) {
            return response()->json(['error' => 'Código de verificação inválido ou expirado.'], 422);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ])->save();
        $this->recordInteraction($user, 'verification');

        return response()->json(['message' => 'E-mail verificado com sucesso.']);
    }

    public function resendCodeEmailVerification()
    {
        $user = Auth::user();
        $rawCode = $this->newCode(6);

        $user->forceFill([
            'verification_code' => Hash::make($rawCode),
            'verification_code_expires_at' => now()->addMinutes(30),
        ])->save();
        Mail::to($user->email)->send(new ResendVerificationCodeMail($rawCode, $user));
        $this->recordInteraction($user, 'verification_code_resent');

        return response()->json(['message' => 'Novo código de verificação enviado com sucesso.']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => [
                'required', 'string', 'different:current_password',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => 'required|string|same:new_password',
        ]);

        $user = Auth::user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['error' => 'A senha atual está incorreta.'], 401);
        }

        $user->forceFill(['password' => Hash::make($data['new_password'])])->save();
        $this->recordInteraction($user, 'password_change');

        return response()->json(['message' => 'Senha alterada com sucesso!']);
    }

    public function sendResetCodeEmail(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();

        if ($user) {
            $rawCode = $this->newCode(8);
            $user->forceFill([
                'reset_password_code' => Hash::make($rawCode),
                'reset_password_expires_at' => now()->addMinutes(10),
            ])->save();

            try {
                Mail::to($user->email)->send(new ResetPasswordMail($rawCode, $user));
                $this->recordInteraction($user, 'password_reset_requested');
            } catch (\Throwable $e) {
                Log::error('Falha ao enviar e-mail de recuperação.', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Não foi possível enviar o e-mail neste momento.'], 503);
            }
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, um código de redefinição será enviado.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'reset_password_code' => 'required|string|min:6|max:16',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();

        if (! $user
            || ! $user->reset_password_expires_at
            || now()->greaterThan($user->reset_password_expires_at)
            || ! $this->matchesCode($data['reset_password_code'], $user->reset_password_code)) {
            return response()->json([
                'error' => true,
                'message' => 'Código de redefinição inválido ou expirado.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'reset_password_code' => null,
            'reset_password_expires_at' => null,
        ])->save();

        $this->recordInteraction($user, 'password_changed');

        return response()->json(['error' => false, 'message' => 'Senha redefinida com sucesso.']);
    }

    public function logout()
    {
        $user = Auth::user();
        $this->recordInteraction($user, 'logout');
        Auth::logout();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function refresh()
    {
        try {
            return response()->json($this->createNewToken(auth()->refresh()));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Token inválido ou não renovável.'], 401);
        }
    }

    public function checkauth()
    {
        return response()->json(['authenticated' => Auth::check()]);
    }

    public function unauthorized()
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    public function me()
    {
        $user = User::query()
            ->with(['profile', 'employer', 'establishments', 'applications'])
            ->findOrFail(Auth::id());

        $this->recordInteraction($user, 'me');

        $employer = $user->employer;
        $establishments = $user->establishments;
        $applications = $user->applications;

        $user->unsetRelation('employer');
        $user->unsetRelation('establishments');
        $user->unsetRelation('applications');

        return response()->json([
            'message' => 'Usuário encontrado com sucesso.',
            'user' => $user,
            'is_employer' => (bool) $employer,
            'employer' => $employer,
            'establishments' => $establishments,
            'applications' => $applications,
        ]);
    }

    public function invite(Request $request)
    {
        $actor = Auth::user();

        if (! $actor->hasProfile('Administrador')
            && ! $actor->hasPermission('user_create')
            && ! $actor->hasPermission('marketing_user_invite')
            && ! $actor->hasPermission('application_manage')) {
            return response()->json(['message' => 'Você não tem permissão para enviar convites.'], 403);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-ZÀ-ÿ\\s]+$/'],
            'email' => 'required|email|max:255|unique:users,email',
            'app_id' => 'required|integer|exists:applications,id',
        ]);

        $application = Application::findOrFail($data['app_id']);
        if (! $actor->hasProfile('Administrador')) {
            $hasApplicationAccess = $actor->applications()
                ->whereKey($application->id)
                ->wherePivot('status', 'active')
                ->exists();
            abort_unless($hasApplicationAccess, 403, 'Você só pode convidar usuários para aplicações atribuídas ao seu perfil.');
        }
        $rawCode = $this->newCode(6);

        $user = DB::transaction(function () use ($data, $rawCode) {
            $user = User::create([
                'first_name' => trim($data['first_name']),
                'email' => strtolower(trim($data['email'])),
                'password' => Hash::make(Str::random(40)),
                'user_name' => $this->uniqueUsername($data['first_name']),
                'verification_code' => Hash::make($rawCode),
                'verification_code_expires_at' => now()->addDay(),
            ]);

            $user->applications()->attach($data['app_id'], [
                'status' => 'pending',
                'role' => 'client',
                'metadata' => json_encode(['invited_by' => $actor->id, 'invited_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE),
                'joined_at' => null,
            ]);

            return $user;
        });

        Mail::to($user->email)->send(new InviteUserMail($user, $rawCode, $application->name, $application->url));
        $this->recordInteraction($user, 'invite_sent', ['application_id' => $application->id]);

        return response()->json([
            'message' => 'Conta criada e convite enviado com sucesso.',
            'user' => $user->load('applications:id,name,slug,url'),
            'application' => $application->only(['id', 'name', 'slug', 'url']),
        ], 201);
    }

    public function completeInvite(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'verification_code' => 'required|string|min:4|max:12',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();

        if (! $user || ! $this->verificationCodeIsValid($user, $data['verification_code'])) {
            return response()->json(['message' => 'Convite inválido ou expirado.'], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'verification_code' => null,
                'verification_code_expires_at' => null,
                'email_verified_at' => now(),
            ])->save();

            $pendingApplicationIds = $user->applications()
                ->wherePivot('status', 'pending')
                ->pluck('applications.id');

            foreach ($pendingApplicationIds as $applicationId) {
                $user->applications()->updateExistingPivot($applicationId, [
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }
        });

        try {
            Mail::to($user->email)->send(new InviteCompleteMail($user));
        } catch (\Throwable $e) {
            Log::warning('Convite concluído, mas e-mail de confirmação falhou.', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }

        $this->recordInteraction($user, 'invite_completed');

        return response()->json([
            'message' => 'Senha criada com sucesso. Você já pode acessar o aplicativo.',
        ]);
    }

    protected function createNewToken($token): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => auth()->user(),
        ];
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';

        do {
            $username = $base . '-' . Str::lower(Str::random(6));
        } while (User::query()->where('user_name', $username)->exists());

        return $username;
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function verificationCodeIsValid(User $user, string $provided): bool
    {
        return $user->verification_code_expires_at
            && now()->lessThanOrEqualTo($user->verification_code_expires_at)
            && $this->matchesCode($provided, $user->verification_code);
    }

    private function matchesCode(string $provided, ?string $stored): bool
    {
        if (! $stored) {
            return false;
        }

        if (Str::startsWith($stored, ['$2y$', '$2a$', '$argon2'])) {
            return Hash::check($provided, $stored);
        }

        return hash_equals($stored, $provided);
    }

    private function recordInteraction(?User $user, string $type, array $content = []): void
    {
        if (! $user) return;

        try {
            $request = request();
            $user->loadMissing(['profile:id,name', 'applications:id,name,slug', 'establishments:id,name,app_id']);
            $labels = [
                'login' => 'Entrou no aplicativo',
                'login_google' => 'Entrou com Google',
                'logout' => 'Saiu do aplicativo',
                'register' => 'Criou uma conta',
                'verification' => 'Verificou o e-mail',
                'password_change' => 'Alterou a senha',
                'password_changed' => 'Redefiniu a senha',
                'password_reset_requested' => 'Solicitou recuperação de senha',
            ];

            Interaction::create([
                'user_id' => $user->id,
                'interaction_type' => $type,
                'outcome' => 'success',
                'severity' => str_contains($type, 'password') ? 'attention' : 'normal',
                'environment' => app()->environment(),
                'entity_id' => $user->id,
                'entity_type' => 'User',
                'request_id' => $request?->attributes->get('request_id'),
                'correlation_id' => $request?->header('X-Correlation-ID') ?: $request?->attributes->get('request_id'),
                'name' => $labels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
                'content' => array_filter(array_merge($content, [
                    'status' => 200,
                    'frontend_page' => $request?->header('X-Frontend-Page') ?: $request?->header('Referer'),
                    'origin' => $request?->header('Origin'),
                    'referer' => $request?->header('Referer'),
                    'ip' => $request?->ip(),
                    'user_agent' => $request?->userAgent(),
                    'user_snapshot' => [
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->user_name,
                        'email' => $user->email,
                        'profile' => $user->profile?->name,
                        'applications' => $user->applications->map->only(['id', 'name', 'slug'])->values()->all(),
                        'establishments' => $user->establishments->map->only(['id', 'name', 'app_id'])->values()->all(),
                    ],
                ]), fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao registrar interação de autenticação.', [
                'user_id' => $user->id, 'type' => $type, 'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveLocation(Request $request, $latitude, $longitude): array
    {
        $city = null;
        $uf = null;

        if ($latitude !== null && $longitude !== null) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'PeterTecnet/1.0'])
                    ->timeout(3)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'json',
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'addressdetails' => 1,
                    ]);

                if ($response->successful()) {
                    $address = (array) data_get($response->json(), 'address', []);
                    $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;
                    $uf = $this->stateToUf($address['state'] ?? null);
                }
            } catch (\Throwable $e) {
                Log::notice('Reverse geocode indisponível.', ['message' => $e->getMessage()]);
            }
        }

        if (! $city || ! $uf) {
            $geo = User::geoFromIp($request->ip());
            $city = $city ?: ($geo['city'] ?? null);
            $uf = $uf ?: ($geo['uf'] ?? null);
        }

        return ['city' => $city, 'uf' => $uf];
    }

    private function stateToUf(?string $state): ?string
    {
        if (! $state) {
            return null;
        }

        $map = [
            'Acre' => 'AC', 'Alagoas' => 'AL', 'Amapá' => 'AP', 'Amazonas' => 'AM',
            'Bahia' => 'BA', 'Ceará' => 'CE', 'Distrito Federal' => 'DF', 'Espírito Santo' => 'ES',
            'Goiás' => 'GO', 'Maranhão' => 'MA', 'Mato Grosso' => 'MT', 'Mato Grosso do Sul' => 'MS',
            'Minas Gerais' => 'MG', 'Pará' => 'PA', 'Paraíba' => 'PB', 'Paraná' => 'PR',
            'Pernambuco' => 'PE', 'Piauí' => 'PI', 'Rio de Janeiro' => 'RJ',
            'Rio Grande do Norte' => 'RN', 'Rio Grande do Sul' => 'RS', 'Rondônia' => 'RO',
            'Roraima' => 'RR', 'Santa Catarina' => 'SC', 'São Paulo' => 'SP', 'Sergipe' => 'SE',
            'Tocantins' => 'TO',
        ];

        return $map[$state] ?? (strlen($state) === 2 ? strtoupper($state) : null);
    }
}
