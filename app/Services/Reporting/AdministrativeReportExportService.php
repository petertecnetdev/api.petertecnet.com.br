<?php

namespace App\Services\Reporting;

use App\Models\AdministrativeReportExport;
use App\Models\EcosystemAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AdministrativeReportExportService
{
    public function __construct(
        private AdministrativeReportService $reports,
        private ReportRendererRegistry $renderers,
        private ReportDataMasker $masker,
    ) {}

    public function create(User $user, string $reportKey, string $format, array $filters, ?string $ip = null, ?string $userAgent = null): AdministrativeReportExport
    {
        abort_unless(collect($this->reports->definitions())->pluck('key')->contains($reportKey), 404, 'Tipo de relatório não encontrado.');
        $renderer = $this->renderers->get($format);
        $export = AdministrativeReportExport::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'report_key' => $reportKey, 'format' => $renderer->format(),
            'status' => 'queued', 'filters' => $filters, 'disk' => 'local', 'request_ip' => $ip, 'request_user_agent' => $userAgent,
            'requested_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $this->audit($export, 'report.requested', ['report_key' => $reportKey, 'format' => $format, 'filters' => $filters]);
        return $export;
    }

    public function generate(AdministrativeReportExport $export): AdministrativeReportExport
    {
        if ($export->status === 'ready' && $export->path && Storage::disk($export->disk)->exists($export->path)) return $export;

        $export->forceFill(['status' => 'processing', 'started_at' => now(), 'error_message' => null])->save();
        try {
            $filters = $export->filters ?? [];
            if ($export->format !== 'pdf') $filters['_row_limit'] = 250000;
            $report = $this->reports->build($export->report_key, $filters);
            $report = $this->masker->apply($report, $export->user);
            $renderer = $this->renderers->get($export->format);
            $contents = $renderer->render($report);
            $filename = 'peter-tecnet-' . Str::slug($export->report_key) . '-' . now()->format('Ymd-His') . '-' . substr($export->uuid, 0, 8) . '.' . $renderer->extension();
            $path = 'reports/admin/' . now()->format('Y/m') . '/' . $filename;
            Storage::disk($export->disk)->put($path, $contents);
            $export->forceFill([
                'status' => 'ready', 'filename' => $filename, 'path' => $path,
                'row_count' => (int) ($report['row_count'] ?? count($report['rows'] ?? [])),
                'completed_at' => now(), 'expires_at' => now()->addDays(7),
            ])->save();
            $this->audit($export, 'report.generated', ['report_key' => $export->report_key, 'format' => $export->format, 'row_count' => $export->row_count, 'filename' => $filename]);
            return $export->fresh();
        } catch (Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 2000), 'completed_at' => now()])->save();
            $this->audit($export, 'report.failed', ['report_key' => $export->report_key, 'format' => $export->format, 'error' => $export->error_message]);
            throw $e;
        }
    }

    public function expireOld(): int
    {
        $count = 0;
        AdministrativeReportExport::query()->where('expires_at', '<', now())->whereIn('status', ['ready', 'failed'])->chunkById(100, function ($exports) use (&$count) {
            foreach ($exports as $export) {
                if ($export->path) Storage::disk($export->disk)->delete($export->path);
                $export->forceFill(['status' => 'expired', 'path' => null])->save();
                $count++;
            }
        });
        return $count;
    }

    private function audit(AdministrativeReportExport $export, string $action, array $after): void
    {
        EcosystemAuditLog::create([
            'user_id' => $export->user_id, 'action' => $action, 'entity_type' => AdministrativeReportExport::class, 'entity_id' => $export->id,
            'before' => null, 'after' => $after, 'ip' => $export->request_ip, 'user_agent' => $export->request_user_agent,
        ]);
    }
}
