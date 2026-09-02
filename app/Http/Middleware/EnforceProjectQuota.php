<?php

namespace App\Http\Middleware;

use App\Models\ApiProject;
use App\Models\ApiUsageRecord;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class EnforceProjectQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiProject|null $project */
        $project = $request->attributes->get('api_project');
        if (! $project) {
            return $next($request);
        }

        $key = 'api-project:' . $project->id;
        $limit = max(1, (int) $project->requests_per_minute);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return ApiResponse::error('RATE_LIMIT_EXCEEDED', 'Limite de requisições do projeto excedido.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
                'limit_per_minute' => $limit,
            ], $request);
        }

        RateLimiter::hit($key, 60);

        $quota = max(0, (int) $project->monthly_request_quota);
        if ($quota > 0) {
            $used = ApiUsageRecord::query()
                ->where('api_project_id', $project->id)
                ->where('occurred_at', '>=', now()->startOfMonth())
                ->count();

            if ($used >= $quota) {
                return ApiResponse::error('MONTHLY_QUOTA_EXCEEDED', 'A cota mensal do projeto foi atingida.', 429, [
                    'quota' => $quota,
                    'used' => $used,
                ], $request);
            }
        }

        return $next($request);
    }
}
