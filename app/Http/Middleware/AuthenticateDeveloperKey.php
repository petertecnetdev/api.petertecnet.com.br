<?php

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiKey;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeveloperKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = trim((string) $request->header('X-API-Key'));

        if ($plainTextKey === '') {
            return ApiResponse::error(
                $request,
                'api_key_required',
                'Envie sua credencial no cabeçalho X-API-Key.',
                401
            );
        }

        $key = ApiKey::query()
            ->with('client')
            ->where('key_hash', hash('sha256', $plainTextKey))
            ->first();

        if (!$key || !$key->client || !$key->isUsable() || !$key->client->isActive()) {
            return ApiResponse::error(
                $request,
                'invalid_api_key',
                'A credencial informada é inválida, expirada ou foi revogada.',
                401
            );
        }

        $expectedEnvironment = $request->is('api/sandbox/v1*') ? 'sandbox' : 'production';

        if ($key->client->environment !== $expectedEnvironment) {
            return ApiResponse::error(
                $request,
                'environment_mismatch',
                'Use uma credencial compatível com o ambiente solicitado.',
                403,
                ['expected_environment' => $expectedEnvironment]
            );
        }

        $request->attributes->set('developer_client', $key->client);
        $request->attributes->set('developer_key', $key);

        if (!$key->last_used_at || $key->last_used_at->lt(now()->subMinutes(5))) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        if (!$key->client->last_used_at || $key->client->last_used_at->lt(now()->subMinutes(5))) {
            $key->client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
