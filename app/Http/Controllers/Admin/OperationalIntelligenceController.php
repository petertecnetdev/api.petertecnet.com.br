<?php

namespace App\Http\Controllers\Admin;

use App\Events\EcosystemUpdated;
use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Services\Operations\OperationalIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OperationalIntelligenceController extends Controller
{
    public function __construct(private readonly OperationalIntelligenceService $intelligence)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        return response()->json([
            'summary' => $this->intelligence->overview(),
            'deployments' => $this->deploymentsPayload(30),
            'alerts' => $this->alertsPayload(50),
            'slos' => $this->slosPayload(),
            'runbooks' => $this->runbooksPayload(),
            'journeys' => $this->journeysPayload(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function issue(Request $request, int $issue): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless(Schema::hasTable('operational_issues'), 404);
        $row = DB::table('operational_issues')->find($issue);
        abort_unless($row, 404, 'Problema operacional não encontrado.');
        return response()->json(['intelligence' => $this->intelligence->analyze($row)]);
    }

    public function deployments(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $limit = max(10, min((int) $request->integer('limit', 100), 250));
        return response()->json(['data' => $this->deploymentsPayload($limit)]);
    }

    public function storeDeployment(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'environment' => ['nullable', 'string', 'max:40'],
            'version' => ['nullable', 'string', 'max:100'],
            'commit_sha' => ['nullable', 'string', 'max:64'],
            'previous_commit_sha' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['succeeded', 'failed', 'rolled_back', 'observed'])],
            'source' => ['nullable', 'string', 'max:48'],
            'deployed_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);
        abort_if(empty($data['commit_sha']) && empty($data['version']), 422, 'Informe commit_sha ou version para identificar o deploy.');

        $deployment = $this->intelligence->recordDeployment($data);
        $this->audit($request, 'operational_deployment.recorded', 'operational_deployments', (int) ($deployment['id'] ?? 0), null, $deployment);
        broadcast(new EcosystemUpdated(['command', 'issues', 'deployments'], 'operational_deployment.recorded'));
        return response()->json(['deployment' => $deployment], 201);
    }

    public function slos(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        return response()->json(['data' => $this->slosPayload()]);
    }

    public function updateSlo(Request $request, int $slo): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless(Schema::hasTable('operational_slo_definitions'), 404);
        $before = DB::table('operational_slo_definitions')->find($slo);
        abort_unless($before, 404, 'SLO não encontrado.');
        $data = $request->validate([
            'availability_target' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_error_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'p95_latency_ms' => ['nullable', 'integer', 'min:1', 'max:120000'],
            'window_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $patch = array_filter($data, fn ($value) => $value !== null);
        $patch['updated_at'] = now();
        DB::table('operational_slo_definitions')->where('id', $slo)->update($patch);
        $after = (array) DB::table('operational_slo_definitions')->find($slo);
        $this->audit($request, 'operational_slo.updated', 'operational_slo_definitions', $slo, (array) $before, $after);
        broadcast(new EcosystemUpdated(['command', 'issues', 'slos'], 'operational_slo.updated'));
        return response()->json(['slo' => $after]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $limit = max(10, min((int) $request->integer('limit', 100), 250));
        return response()->json(['data' => $this->alertsPayload($limit)]);
    }

    public function repairPlan(Request $request, int $issue): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless(Schema::hasTable('operational_issues'), 404);
        $row = DB::table('operational_issues')->find($issue);
        abort_unless($row, 404, 'Problema operacional não encontrado.');
        $plan = $this->intelligence->createRepairPlan($row, $request->user()?->id);
        $this->audit($request, 'operational_repair_plan.created', 'operational_repair_plans', (int) $plan['id'], null, $plan);
        broadcast(new EcosystemUpdated(['command', 'issues'], 'operational_repair_plan.created'));
        return response()->json(['repair_plan' => $plan], 201);
    }

    private function deploymentsPayload(int $limit): array
    {
        if (! Schema::hasTable('operational_deployments')) return [];
        return DB::table('operational_deployments as d')->leftJoin('applications as a', 'a.id', '=', 'd.application_id')
            ->orderByDesc('d.deployed_at')->limit($limit)
            ->get(['d.*', 'a.name as application_name', 'a.slug as application_slug'])
            ->map(function ($row) {
                $payload = (array) $row;
                $payload['metadata'] = $this->decodeJson($payload['metadata'] ?? null);
                return $payload;
            })->values()->all();
    }

    private function alertsPayload(int $limit): array
    {
        if (! Schema::hasTable('operational_alerts')) return [];
        return DB::table('operational_alerts as al')->join('operational_issues as oi', 'oi.id', '=', 'al.operational_issue_id')
            ->orderByRaw("CASE al.level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'warning' THEN 3 ELSE 4 END")
            ->orderByDesc('al.last_triggered_at')->limit($limit)
            ->get(['al.*', 'oi.fingerprint', 'oi.title', 'oi.domain', 'oi.impact_score', 'oi.status as issue_status'])
            ->map(function ($row) {
                $payload = (array) $row;
                $payload['metadata'] = $this->decodeJson($payload['metadata'] ?? null);
                return $payload;
            })->values()->all();
    }

    private function slosPayload(): array
    {
        if (! Schema::hasTable('operational_slo_definitions')) return [];
        return DB::table('operational_slo_definitions as s')->leftJoin('applications as a', 'a.id', '=', 's.application_id')
            ->orderBy('s.domain')->orderBy('a.name')->get(['s.*', 'a.name as application_name', 'a.slug as application_slug'])
            ->map(function ($row) {
                $payload = (array) $row;
                $payload['metadata'] = $this->decodeJson($payload['metadata'] ?? null);
                return $payload;
            })->values()->all();
    }

    private function runbooksPayload(): array
    {
        if (! Schema::hasTable('operational_runbooks')) return [];
        return DB::table('operational_runbooks')->where('enabled', true)->orderBy('title')->get()->map(function ($row) {
            $payload = (array) $row;
            $payload['steps'] = $this->decodeJson($payload['steps'] ?? null) ?: [];
            return $payload;
        })->values()->all();
    }

    private function journeysPayload(): array
    {
        if (! Schema::hasTable('operational_journey_definitions')) return [];
        return DB::table('operational_journey_definitions')->where('enabled', true)->orderByDesc('critical')->orderBy('name')->get()->map(function ($row) {
            $payload = (array) $row;
            $payload['steps'] = $this->decodeJson($payload['steps'] ?? null) ?: [];
            return $payload;
        })->values()->all();
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) return $value;
        if (! is_string($value) || $value === '') return null;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('security_view') || $user->hasPermission('operations_view') || $user->hasPermission('ecosystem_manage')), 403);
    }

    private function authorizeManage(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('operations_manage') || $user->hasPermission('ecosystem_manage')), 403);
    }

    private function audit(Request $request, string $action, string $entityType, int $entityId, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'before' => $before, 'after' => $after, 'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
