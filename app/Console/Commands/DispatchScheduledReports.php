<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAdministrativeReportExport;
use App\Models\AdministrativeReportSchedule;
use App\Services\Reporting\AdministrativeReportExportService;
use App\Services\Reporting\ReportScheduleService;
use Illuminate\Console\Command;

class DispatchScheduledReports extends Command
{
    protected $signature = 'reports:dispatch-scheduled';
    protected $description = 'Gera relatórios administrativos agendados que chegaram ao horário de execução.';

    public function handle(AdministrativeReportExportService $exports, ReportScheduleService $schedules): int
    {
        $dispatched = 0;
        AdministrativeReportSchedule::query()
            ->with('user')
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->chunkById(50, function ($rows) use ($exports, $schedules, &$dispatched) {
                foreach ($rows as $schedule) {
                    if (! $schedule->user) {
                        $schedule->forceFill(['is_active' => false])->save();
                        continue;
                    }
                    $export = $exports->create($schedule->user, $schedule->report_key, $schedule->format, $schedule->filters ?? []);
                    GenerateAdministrativeReportExport::dispatch($export->id);
                    $schedule->forceFill(['last_run_at' => now(), 'next_run_at' => $schedules->nextRun($schedule, now()->addMinute())])->save();
                    $dispatched++;
                }
            });

        $expired = $exports->expireOld();
        $this->info("{$dispatched} relatório(s) agendado(s) enviados para geração; {$expired} exportação(ões) expirada(s) limpa(s).");
        return self::SUCCESS;
    }
}
