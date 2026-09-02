<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        /** @var ApiCredential|null $credential */
        $credential = $request->attributes->get('api_credential');
        if (! $credential) {
            return ApiResponse::error('API_CREDENTIAL_CONTEXT_MISSING', 'Credencial de projeto não resolvida.', 401, [], $request);
        }

        foreach ($scopes as $scope) {
            if (! $credential->allows($scope) || ! $credential->project->allows($scope)) {
                return ApiResponse::error('INSUFFICIENT_SCOPE', 'A credencial não possui o escopo necessário.', 403, ['required_scope' => $scope], $request);
            }
        }

        return $next($request);
    }
}
