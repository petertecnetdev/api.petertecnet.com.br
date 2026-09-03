<?php

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformStatusController extends Controller
{
    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $operational = collect($checks)->every(fn (string $status) => $status === 'operational');

        return response()->json([
            'data' => [
                'service' => 'Peter Tecnet API',
                'status' => $operational ? 'operational' : 'degraded',
                'version' => 'v1',
                'timestamp' => now()->toISOString(),
                'checks' => $checks,
            ],
        ], $operational ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('select 1');
            return 'operational';
        } catch (Throwable) {
            return 'degraded';
        }
    }

    private function checkCache(): string
    {
        try {
            $key = 'developer-platform-health:' . uniqid('', true);
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $ok ? 'operational' : 'degraded';
        } catch (Throwable) {
            return 'degraded';
        }
    }
}
