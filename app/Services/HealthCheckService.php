<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class HealthCheckService
{
    /**
     * Run only dependency checks that are safe to expose through the public
     * readiness endpoint. No hostnames, credentials or exception messages are
     * returned to callers.
     */
    public function check(): array
    {
        $checks = [
            'application' => $this->probe(static fn () => true),
            'database' => $this->probe(static function (): void {
                DB::select('SELECT 1');
            }),
            'cache' => $this->probe(static function (): void {
                $key = 'health:' . Str::uuid()->toString();
                $value = Str::random(24);

                Cache::put($key, $value, 10);
                $cached = Cache::get($key);
                Cache::forget($key);

                if (!is_string($cached) || !hash_equals($value, $cached)) {
                    throw new RuntimeException('Cache health probe failed.');
                }
            }),
            'storage' => $this->probe(static function (): void {
                $path = storage_path();

                if (!is_dir($path) || !is_writable($path)) {
                    throw new RuntimeException('Application storage is not writable.');
                }
            }),
        ];

        $healthy = true;
        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'ok') {
                $healthy = false;
                break;
            }
        }

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    private function probe(Closure $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $probe();

            return [
                'status' => 'ok',
                'latency_ms' => $this->elapsedMilliseconds($startedAt),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'status' => 'fail',
                'latency_ms' => $this->elapsedMilliseconds($startedAt),
            ];
        }
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
