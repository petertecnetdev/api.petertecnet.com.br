<?php

namespace App\Http\Controllers;

use App\Services\PlatformHealthService;
use Illuminate\Http\JsonResponse;

class PlatformHealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'petertecnet-api',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready(PlatformHealthService $health): JsonResponse
    {
        $readiness = $health->readiness();
        $ready = $readiness['ready'];

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => 'petertecnet-api',
            'checks' => $readiness['checks'],
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }
}
