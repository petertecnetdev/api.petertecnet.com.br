<?php

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleDeveloperCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isDeveloperPublicPath($request) || strtoupper($request->method()) !== 'OPTIONS') {
            return $next($request);
        }

        $origin = trim((string) $request->header('Origin'));
        $clientId = trim((string) $request->query('client_id'));
        $expectedEnvironment = $request->is('api/sandbox/v1/*') ? 'sandbox' : 'production';

        if ($origin === '' || $clientId === '') {
            return response()->json([
                'error' => [
                    'code' => 'cors_client_required',
                    'message' => 'Integrações de navegador devem informar client_id na query string.',
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        $client = ApiClient::query()
            ->where('client_id', $clientId)
            ->where('environment', $expectedEnvironment)
            ->where('status', 'active')
            ->first();

        if (!$client || !in_array($origin, $client->allowed_origins ?? [], true)) {
            return response()->json([
                'error' => [
                    'code' => 'origin_not_allowed',
                    'message' => 'A origem do navegador não está autorizada para esta integração.',
                    'details' => (object) [],
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        return response('', 204)->withHeaders([
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Accept, Content-Type, X-API-Key, X-Request-ID',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }

    private function isDeveloperPublicPath(Request $request): bool
    {
        return $request->is(
            'api/v1/establishments*',
            'api/v1/items*',
            'api/sandbox/v1/establishments*',
            'api/sandbox/v1/items*'
        );
    }
}
