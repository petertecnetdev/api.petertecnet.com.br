<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAdministrativeReportExport;
use App\Models\AdministrativeReportExport;
use App\Services\Reporting\AdministrativeReportAccessService;
use App\Services\Reporting\AdministrativeReportExportService;
use App\Services\Reporting\AdministrativeReportFilterService;
use App\Services\Reporting\AdministrativeReportService;
use App\Services\Reporting\ReportRendererRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdministrativeReportExportController extends Controller
{
    public function __construct(
        private AdministrativeReportExportService $exports,
        private AdministrativeReportAccessService $access,
        private AdministrativeReportService $reports,
        private ReportRendererRegistry $renderers,
        private AdministrativeReportFilterService $filterService,
    ) {}

    public function index(Request $request)
    {
        $this->access->assertManage($request->user());
        $query = AdministrativeReportExport::query()->with('user:id,first_name,last_name,email')->latest('id');
        if ($request->filled('report_key')) $query->where('report_key', $request->string('report_key'));
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('format')) $query->where('format', $request->string('format'));
        return response()->json($query->paginate(min(max((int) $request->input('per_page', 30), 10), 100)));
    }

    public function store(Request $request)
    {
        $this->access->assertManage($request->user());
        $data = $request->validate([
            'report_key' => ['required', 'string', 'max:80'],
            'format' => ['required', Rule::in($this->renderers->formats())],
            'filters' => ['nullable', 'array'],
        ]);
        $this->access->assertManage($request->user(), $data['report_key']);
        abort_unless(collect($this->reports->definitions())->pluck('key')->contains($data['report_key']), 404, 'Tipo de relatório não encontrado.');
        $filters = $this->filterService->validate($data['filters'] ?? []);
        $export = $this->exports->create($request->user(), $data['report_key'], $data['format'], $filters, $request->ip(), $request->userAgent());
        GenerateAdministrativeReportExport::dispatch($export->id);
        return response()->json(['export' => $export, 'message' => 'Relatório enviado para geração.'], 202);
    }

    public function show(Request $request, AdministrativeReportExport $export)
    {
        $this->access->assertManage($request->user(), $export->report_key);
        return response()->json(['export' => $export->load('user:id,first_name,last_name,email')]);
    }

    public function download(Request $request, AdministrativeReportExport $export)
    {
        $this->access->assertManage($request->user(), $export->report_key);
        abort_unless($export->download_ready && $export->path && Storage::disk($export->disk)->exists($export->path), 404, 'Este relatório ainda não está disponível ou já expirou.');
        return Storage::disk($export->disk)->download($export->path, $export->filename, ['Content-Type' => $this->renderers->get($export->format)->mimeType()]);
    }
}
