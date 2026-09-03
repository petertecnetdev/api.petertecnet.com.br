<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalIntelligenceService;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyzeOperationalHealth extends Command
{
    protected $signature = 'operations:analyze {--hours=48} {--limit=1500}';
    protected $description = 'Sincroniza telemetria crítica, problemas operacionais, correlações, SLOs, anomalias e alertas.';

    public function handle(
        OperationalTelemetryService $telemetry,
        OperationalIssueService $issues,
        OperationalIntelligenceService $intelligence,
    ): int {
        if (! $telemetry->available() || ! $issues->available()) {
            $this->components->info('Telemetria ou tabelas operacionais ainda não estão disponíveis.');
            return self::SUCCESS;
        }

        $hours = max(1, min((int) $this->option('hours'), 336));
        $limit = max(50, min((int) $this->option('limit'), 5000));
        $events = $telemetry->events($hours, $limit);
        $issues->sync($events);

        $analyzed = 0;
        if ($intelligence->available() && Schema::hasTable('operational_issues')) {
            DB::table('operational_issues')
                ->whereIn('status', ['new', 'acknowledged', 'investigating', 'fixed', 'monitoring'])
                ->orderByDesc('impact_score')
                ->orderByDesc('last_seen_at')
                ->limit(300)
                ->get()
                ->each(function ($issue) use ($intelligence, &$analyzed) {
                    $intelligence->analyze($issue);
                    $analyzed++;
                });
        }

        $this->components->info("Telemetria sincronizada: {$events->count()} evento(s); {$analyzed} problema(s) analisado(s).");
        return self::SUCCESS;
    }
}
