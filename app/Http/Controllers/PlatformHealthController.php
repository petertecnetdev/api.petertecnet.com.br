<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'runtime' => $this->checkRuntime(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => 'petertecnet-api',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        $key = 'health:readiness:' . bin2hex(random_bytes(8));

        try {
            Cache::put($key, 'ok', 10);
            $healthy = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $healthy;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRuntime(): bool
    {
        return is_writable(storage_path())
            && is_writable(storage_path('logs'))
            && is_writable(base_path('bootstrap/cache'));
    }
}
