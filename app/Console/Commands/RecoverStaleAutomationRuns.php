<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverStaleAutomationRuns extends Command
{
    protected $signature = 'automation:recover-stale {--minutes=120} {--dry-run}';
    protected $description = 'Libera execuções antigas abandonadas após interrupção da fila.';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 5 || $minutes > 10080) {
            $this->error('Informe um intervalo entre 5 e 10080 minutos.');
            return self::FAILURE;
        }

        $query = DB::table('automation_worker_runs')
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes($minutes));

        $count = $query->count();
        if (! $this->option('dry-run') && $count > 0) {
            $query->update([
                'status' => 'failed',
                'last_error' => 'StaleAutomationRun',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info($count.' execução(ões) '.($this->option('dry-run') ? 'antigas detectadas' : 'antigas finalizadas').'.');
        return self::SUCCESS;
    }
}
