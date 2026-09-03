<?php

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDeveloperScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        /** @var ApiClient|null $client */
        $client = $request->attributes->get('developer_client');

        if (!$client) {
            return ApiResponse::error($request, 'api_key_required', 'Credencial de API não resolvida.', 401);
        }

        foreach ($scopes as $scope) {
            if (!$client->hasScope($scope)) {
                return ApiResponse::error(
                    $request,
                    'insufficient_scope',
                    'A credencial não possui permissão para esta operação.',
                    403,
                    ['required_scope' => $scope]
                );
            }
        }

        return $next($request);
    }
}
