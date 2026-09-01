<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EcosystemSsoController extends Controller
{
    private const HANDOFF_TTL_SECONDS = 60;

    public function createHandoff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = Application::query()
            ->where('slug', $data['application'])
            ->where('is_active', true)
            ->firstOrFail();

        $user = $request->user();
        $hasAccess = $user->applications()
            ->where('applications.id', $application->id)
            ->where(function ($query) {
                $query->where('application_user.status', 'active')
                    ->orWhereNull('application_user.status');
            })
            ->exists();

        abort_unless($hasAccess, 403, 'Sua Conta Peter Tecnet não possui acesso a este aplicativo.');

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), [
            'user_id' => (int) $user->id,
            'application_id' => (int) $application->id,
            'auth_version' => (int) ($user->auth_version ?? 0),
        ], now()->addSeconds(self::HANDOFF_TTL_SECONDS));

        return response()->json([
            'success' => true,
            'data' => [
                'handoff_code' => $code,
                'expires_in' => self::HANDOFF_TTL_SECONDS,
                'application' => $application->only(['id', 'slug', 'name', 'url']),
            ],
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handoff_code' => ['required', 'string', 'size:64'],
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = Application::query()
            ->where('slug', $data['application'])
            ->where('is_active', true)
            ->firstOrFail();

        $handoff = Cache::pull($this->cacheKey($data['handoff_code']));

        if (! is_array($handoff)
            || (int) ($handoff['application_id'] ?? 0) !== (int) $application->id) {
            return response()->json([
                'success' => false,
                'message' => 'Código SSO inválido, expirado ou já utilizado.',
                'code' => 'SSO_HANDOFF_INVALID',
            ], 401);
        }

        $user = User::query()->find($handoff['user_id'] ?? null);
        if (! $user || (int) ($user->auth_version ?? 0) !== (int) ($handoff['auth_version'] ?? 0)) {
            return response()->json([
                'success' => false,
                'message' => 'A sessão de origem não é mais válida.',
                'code' => 'SSO_SESSION_INVALID',
            ], 401);
        }

        $hasAccess = $user->applications()
            ->where('applications.id', $application->id)
            ->where(function ($query) {
                $query->where('application_user.status', 'active')
                    ->orWhereNull('application_user.status');
            })
            ->exists();

        abort_unless($hasAccess, 403, 'Sua Conta Peter Tecnet não possui mais acesso a este aplicativo.');

        $token = auth('api')->login($user);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => $user,
                'application' => $application->only(['id', 'slug', 'name', 'url']),
            ],
        ]);
    }

    private function cacheKey(string $code): string
    {
        return 'ecosystem:sso:' . hash('sha256', $code);
    }
}
