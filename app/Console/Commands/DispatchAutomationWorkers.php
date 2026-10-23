<?php

namespace App\Console\Commands;

use App\Jobs\RunAutomationWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchAutomationWorkers extends Command
{
    protected $signature = 'automation:dispatch
        {--app= : Slug da aplicação}
        {--worker= : Identificador do worker}
        {--dry-run : Inspecionar sem alterar o estado}';

    protected $description = 'Despacha workers habilitados, respeitando aplicação, frequência e concorrência.';

    public function handle(): int
    {
        if (! config('automation_workers.enabled', false)) {
            $this->line('Automações globalmente desativadas (AUTOMATION_WORKERS_ENABLED=false).');
            return self::SUCCESS;
        }

        $worker = trim((string) $this->option('worker'));
        if ($worker !== '' && ! array_key_exists($worker, (array) config('automation_workers.workers', []))) {
            $this->error('Worker desconhecido.');
            return self::FAILURE;
        }

        $query = DB::table('automation_worker_controls as control')
            ->join('applications as app', 'app.id', '=', 'control.app_id')
            ->where('app.is_active', true)
            ->where('control.enabled', true)
            ->select('control.*')
            ->orderBy('control.id')
            ->limit(500);

        if ($worker !== '') {
            $query->where('control.worker_key', $worker);
        }
        if ($this->option('app')) {
            $query->where('app.slug', (string) $this->option('app'));
        }

        $dispatched = 0;
        foreach ($query->get() as $control) {
            if (! array_key_exists($control->worker_key, (array) config('automation_workers.workers', []))) {
                continue;
            }

            $due = static function (object $value): bool {
                $interval = max(5, (int) $value->interval_minutes);
                return $value->last_dispatched_at === null
                    || \Carbon\CarbonImmutable::parse($value->last_dispatched_at)
                        ->lessThanOrEqualTo(now()->subMinutes($interval));
            };

            if (! $due($control)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Elegível: {$control->worker_key} / app {$control->app_id}");
                $dispatched++;
                continue;
            }

            $runId = DB::transaction(function () use ($control, $due): ?int {
                $current = DB::table('automation_worker_controls')
                    ->where('id', $control->id)
                    ->lockForUpdate()
                    ->first();

                if (! $current || ! $current->enabled || ! $due($current)) {
                    return null;
                }

                // Never enqueue concurrent runs of a worker within one application.
                if (DB::table('automation_worker_runs')
                    ->where('app_id', $current->app_id)
                    ->where('worker_key', $current->worker_key)
                    ->whereIn('status', ['queued', 'running'])
                    ->exists()) {
                    return null;
                }

                $runId = DB::table('automation_worker_runs')->insertGetId([
                    'app_id' => $current->app_id,
                    'worker_key' => $current->worker_key,
                    'status' => 'queued',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('automation_worker_controls')->where('id', $current->id)->update([
                    'last_dispatched_at' => now(),
                    'updated_at' => now(),
                ]);

                return $runId;
            }, 3);

            if ($runId !== null) {
                // Dispatched only after committing the run record.
                try {
                    RunAutomationWorker::dispatch($runId);
                    $dispatched++;
                } catch (\Throwable $exception) {
                    DB::table('automation_worker_runs')->where('id', $runId)
                        ->where('status', 'queued')->update([
                            'status' => 'failed',
                            'last_error' => get_class($exception),
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);
                    throw $exception;
                }
            }
        }

        $this->info(($this->option('dry-run') ? 'Elegíveis' : 'Despachados').": {$dispatched}");
        return self::SUCCESS;
    }
}
