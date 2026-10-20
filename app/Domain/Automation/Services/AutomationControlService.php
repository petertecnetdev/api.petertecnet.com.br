<?php

namespace App\Domain\Automation\Services;

use App\Jobs\RunAutomationWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Validation\ValidationException;

final class AutomationControlService
{
    /** @return array<string,mixed> */
    public function dashboard(int $appId): array
    {
        $controls = DB::table('automation_worker_controls')->where('app_id', $appId)
            ->get()->keyBy('worker_key');

        $latest = DB::table('automation_worker_runs')->where('app_id', $appId)
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'worker_key', 'status', 'result', 'last_error', 'created_at', 'started_at', 'finished_at'])
            ->map(static function ($run): array {
                return [
                    'id' => $run->id,
                    'worker_key' => $run->worker_key,
                    'status' => $run->status,
                    'result' => $run->result ? json_decode($run->result, true) : null,
                    'error_type' => $run->last_error,
                    'created_at' => $run->created_at,
                    'started_at' => $run->started_at,
                    'finished_at' => $run->finished_at,
                ];
            });

        $workers = [];
        foreach ((array) config('automation_workers.workers', []) as $key => $definition) {
            $control = $controls->get($key);
            $workers[] = [
                'key' => $key,
                'enabled' => (bool) ($control->enabled ?? false),
                'interval_minutes' => (int) ($control->interval_minutes ?? $definition['interval_minutes'] ?? 60),
                'last_dispatched_at' => $control->last_dispatched_at ?? null,
                'queue' => (string) config('automation_workers.queue', 'curation'),
            ];
        }

        return [
            'global_enabled' => (bool) config('automation_workers.enabled', false),
            'workers' => $workers,
            'recent_runs' => $latest->values()->all(),
        ];
    }

    /** @return array{key:string,enabled:bool,interval_minutes:int} */
    public function configure(int $appId, string $key, bool $enabled, int $interval, int $actorId): array
    {
        $this->assertRegistered($key);
        if ($interval < 5 || $interval > 1440) {
            throw ValidationException::withMessages(['interval_minutes' => 'O intervalo deve estar entre 5 e 1440 minutos.']);
        }

        DB::table('automation_worker_controls')->updateOrInsert(
            ['app_id' => $appId, 'worker_key' => $key],
            [
                'enabled' => $enabled,
                'interval_minutes' => $interval,
                'updated_at' => now(),
            ]
        );

        Log::notice('automation.worker_configuration_changed', [
            'app_id' => $appId,
            'worker_key' => $key,
            'enabled' => $enabled,
            'interval_minutes' => $interval,
            'actor_id' => $actorId,
        ]);

        return ['key' => $key, 'enabled' => $enabled, 'interval_minutes' => $interval];
    }

    /**
     * Manual execution must use the same run table and queue as scheduled jobs.
     * A locked control record serializes requests on databases with row locking.
     */
    public function dispatchNow(int $appId, string $key, int $actorId): int
    {
        $this->assertRegistered($key);
        if (! config('automation_workers.enabled', false)) {
            throw new ConflictHttpException('A infraestrutura de automações está desativada.');
        }

        $runId = DB::transaction(function () use ($appId, $key): int {
            $control = DB::table('automation_worker_controls')
                ->where('app_id', $appId)->where('worker_key', $key)
                ->lockForUpdate()->first();

            if (! $control || ! $control->enabled) {
                throw new ConflictHttpException('O worker não está habilitado para esta aplicação.');
            }
            if (DB::table('automation_worker_runs')->where('app_id', $appId)
                ->where('worker_key', $key)
                ->whereIn('status', ['queued', 'running'])->exists()) {
                throw new ConflictHttpException('Este worker já possui uma execução pendente.');
            }

            $runId = DB::table('automation_worker_runs')->insertGetId([
                'app_id' => $appId, 'worker_key' => $key, 'status' => 'queued',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('automation_worker_controls')
                ->where('id', $control->id)
                ->update(['last_dispatched_at' => now(), 'updated_at' => now()]);
            return $runId;
        }, 3);

        try {
            RunAutomationWorker::dispatch($runId);
        } catch (\Throwable $exception) {
            DB::table('automation_worker_runs')->where('id', $runId)
                ->where('status', 'queued')->update([
                    'status' => 'failed', 'last_error' => get_class($exception),
                    'finished_at' => now(), 'updated_at' => now(),
                ]);
            throw $exception;
        }

        Log::notice('automation.worker_manual_dispatch', [
            'app_id' => $appId, 'worker_key' => $key,
            'run_id' => $runId, 'actor_id' => $actorId,
        ]);
        return $runId;
    }

    private function assertRegistered(string $key): void
    {
        if (! array_key_exists($key, (array) config('automation_workers.workers', []))) {
            throw ValidationException::withMessages(['worker' => 'Worker não cadastrado no servidor.']);
        }
    }
}
