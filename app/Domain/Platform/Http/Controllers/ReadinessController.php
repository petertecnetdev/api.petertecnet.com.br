<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ReadinessController extends Controller
{
    public function ready(Request $request)
    {
        $checks = $this->checks();
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'ready' => $ready,
            'checks' => $checks,
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    public function mutationProbe(Request $request, ApplicationContext $context)
    {
        $checks = $this->checks();
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'ready' => $ready,
            'authenticated' => (bool) $request->user(),
            'application' => [
                'id' => $context->id(),
                'slug' => $context->slug(),
            ],
            'checks' => $checks,
            'probe_id' => Str::uuid()->toString(),
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    private function checks(): array
    {
        return [
            'database' => $this->databaseReady(),
            'cache' => $this->cacheReady(),
            'idempotency' => Schema::hasTable('idempotent_requests'),
            'auth_guard' => (bool) config('auth.guards.api'),
        ];
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheReady(): bool
    {
        $key = 'health:ready:'.Str::uuid()->toString();
        try {
            Cache::put($key, 'ok', 10);
            $ready = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $ready;
        } catch (Throwable) {
            try { Cache::forget($key); } catch (Throwable) {}
            return false;
        }
    }
}
