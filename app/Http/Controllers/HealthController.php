<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(HealthCheckService $healthCheck): JsonResponse
    {
        $result = $healthCheck->check();
        $statusCode = ($result['status'] ?? null) === 'ok' ? 200 : 503;

        return response()
            ->json($result, $statusCode)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
