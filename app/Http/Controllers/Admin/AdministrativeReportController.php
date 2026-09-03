<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\AdministrativeReportAccessService;
use App\Services\Reporting\AdministrativeReportExportService;
use App\Services\Reporting\AdministrativeReportFilterService;
use App\Services\Reporting\AdministrativeReportService;
use App\Services\Reporting\ReportRendererRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdministrativeReportController extends Controller
{
    public function __construct(
        private AdministrativeReportService $reports,
        private AdministrativeReportExportService $exports,
        private AdministrativeReportAccessService $access,
        private ReportRendererRegistry $renderers,
        private AdministrativeReportFilterService $filterService,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertManage($request->user());
        $metadata = $this->reports->metadata();
        $metadata['reports'] = collect($metadata['reports'] ?? [])
            ->filter(fn ($definition) => $this->access->canAccess($request->user(), (string) ($definition['key'] ?? '')))
            ->values();
        return response()->json($metadata);
    }

    public function pdf(Request $request, string $report)
    {
        return $this->download($request, $report, 'pdf');
    }

    public function download(Request $request, string $report, string $format)
    {
        $this->access->assertManage($request->user(), $report);
        $this->renderers->get($format);
        $filters = $this->filterService->validate($request->query());
        $export = $this->exports->create($request->user(), $report, $format, $filters, $request->ip(), $request->userAgent());
        $export = $this->exports->generate($export->load('user'));
        abort_unless($export->path && Storage::disk($export->disk)->exists($export->path), 500, 'O arquivo do relatório não foi produzido.');
        return Storage::disk($export->disk)->download($export->path, $export->filename, ['Content-Type' => $this->renderers->get($format)->mimeType()]);
    }
}
