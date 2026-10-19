<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RunAutomationWorker implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('automation_workers.queue', 'curation'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $run = DB::table('automation_worker_runs')->where('id', $this->runId)->first();
        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }

        $enabled = (bool) config('automation_workers.enabled', false)
            && DB::table('automation_worker_controls')
                ->where('app_id', $run->app_id)
                ->where('worker_key', $run->worker_key)
                ->where('enabled', true)
                ->exists();

        if (! $enabled) {
            DB::table('automation_worker_runs')->where('id', $this->runId)->update([
                'status' => 'skipped',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        $definition = config('automation_workers.workers.'.$run->worker_key);
        if (! is_array($definition) || ! is_string($definition['handler'] ?? null)) {
            throw new RuntimeException('Worker não cadastrado na configuração.');
        }

        DB::table('automation_worker_runs')->where('id', $this->runId)->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'updated_at' => now(),
        ]);

        // Only classes declared in server-side configuration can execute.
        $result = app($definition['handler'])->run((int) $run->app_id);

        DB::table('automation_worker_runs')->where('id', $this->runId)->update([
            'status' => 'succeeded',
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => null,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('automation_worker_runs')
            ->where('id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                // The exception message may contain private upstream data.
                'last_error' => $exception ? get_class($exception) : 'UnknownError',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('automation.worker_failed', [
            'run_id' => $this->runId,
            'exception_type' => $exception ? get_class($exception) : 'UnknownError',
        ]);
    }
}
