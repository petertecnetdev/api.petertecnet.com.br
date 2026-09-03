<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reporting\AdministrativeReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdministrativeReportController extends Controller
{
    public function __construct(private AdministrativeReportService $reports)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        $metadata = $this->reports->metadata();
        $user = $request->user();
        $metadata['reports'] = collect($metadata['reports'] ?? [])
            ->filter(fn (array $definition) => $this->canAccessReport($user, (string) ($definition['key'] ?? '')))
            ->values();

        return response()->json($metadata);
    }

    public function pdf(Request $request, string $report)
    {
        $this->authorizeAccess($request, $report);

        $allowedReports = collect($this->reports->definitions())->pluck('key')->all();
        abort_unless(in_array($report, $allowedReports, true), 404, 'Tipo de relatório não encontrado.');

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::in(['success', 'denied', 'error'])],
            'provider' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'action' => ['nullable', 'string', 'max:150'],
        ]);

        $payload = $this->reports->build($report, $filters);
        $filename = 'peter-tecnet-' . Str::slug($report) . '-' . now()->format('Ymd-His') . '.pdf';

        return Pdf::loadView('reports.administrative', ['report' => $payload])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    private function authorizeAccess(Request $request, ?string $report = null): void
    {
        $user = $request->user();
        $canManage = $user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        );

        abort_unless($canManage, 403, 'Usuário sem permissão para gerar relatórios administrativos.');
        abort_unless($this->canAccessReport($user, $report), 403, 'Usuário sem permissão para gerar este relatório administrativo.');
    }

    private function canAccessReport($user, ?string $report): bool
    {
        if (! $user || $report === null || $report === '') return true;
        if ($user->hasProfile('Administrador')) return true;
        if ($report === 'audit') return $user->hasPermission('audit_view');
        if ($report === 'financial') return $user->hasPermission('finance_view');
        return true;
    }
}
