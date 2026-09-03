<?php

namespace App\Jobs;

use App\Models\AdministrativeReportExport;
use App\Services\Reporting\AdministrativeReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAdministrativeReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public int $exportId) {}

    public function handle(AdministrativeReportExportService $service): void
    {
        $export = AdministrativeReportExport::query()->with('user')->find($this->exportId);
        if (! $export || $export->status === 'expired') return;
        $service->generate($export);
    }
}
