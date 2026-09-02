<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireProductionProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->attributes->get('api_project');
        if ($project && $project->environment !== 'production') {
            return ApiResponse::error(
                'SANDBOX_LIVE_RESOURCE_ACCESS_DENIED',
                'Credenciais sandbox não podem acessar dados reais. Use os endpoints sandbox.',
                403,
                [],
                $request
            );
        }

        return $next($request);
    }
}
