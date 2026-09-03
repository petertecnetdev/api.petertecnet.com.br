<?php

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDeveloperOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = trim((string) $request->header('Origin'));

        if ($origin === '') {
            return $next($request);
        }

        /** @var ApiClient|null $client */
        $client = $request->attributes->get('developer_client');
        $allowedOrigins = array_values(array_filter($client?->allowed_origins ?? []));

        if (!in_array($origin, $allowedOrigins, true)) {
            return ApiResponse::error(
                $request,
                'origin_not_allowed',
                'A origem do navegador não está autorizada para esta integração.',
                403,
                ['origin' => $origin]
            );
        }

        return $next($request);
    }
}
