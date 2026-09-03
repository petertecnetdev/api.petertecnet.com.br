<?php

namespace App\Console\Commands;

use App\Events\OperationalSnapshotUpdated;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorEcosystemOperations extends Command
{
    protected $signature = 'operations:monitor {--no-broadcast : Do not emit the realtime refresh signal}';

    protected $description = 'Probe ecosystem applications, refresh runtime heartbeats, roll up telemetry and correlate operational issues.';

    public function handle(OperationalTelemetryService $telemetry, OperationalIssueService $issues): int
    {
        $telemetry->heartbeatScheduler();

        $probeSummary = $telemetry->probeApplications();
        $backup = $telemetry->inspectBackup();
        $telemetry->rollupAndPrune();
        $issues->evaluateFromCurrentState();

        if (! $this->option('no-broadcast')) {
            try {
                event(new OperationalSnapshotUpdated('operations:monitor'));
            } catch (\Throwable $exception) {
                Log::warning('Mission Control realtime signal failed; polling fallback remains available.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Mission Control refreshed: %d checked, %d operational, %d degraded, %d down; backup=%s.',
            $probeSummary['checked'] ?? 0,
            $probeSummary['operational'] ?? 0,
            $probeSummary['degraded'] ?? 0,
            $probeSummary['down'] ?? 0,
            $backup['status'] ?? 'unknown',
        ));

        return self::SUCCESS;
    }
}
