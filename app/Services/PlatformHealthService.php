<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PlatformHealthService
{
    public function readiness(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'runtime' => $this->checkRuntime(),
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
        ];
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
