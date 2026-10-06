<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemHealthService
{
    public function live(): array
    {
        return [
            'status' => 'ok',
            'service' => config('app.name'),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function ready(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $ready = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return [
            'status' => $ready ? 'ok' : 'degraded',
            'ready' => $ready,
            'service' => config('app.name'),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'system-health:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            $healthy = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['status' => $healthy ? 'ok' : 'error'];
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }
}
