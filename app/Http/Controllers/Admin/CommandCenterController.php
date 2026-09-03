<?php

namespace App\Http\Controllers\Admin;

use App\Events\OperationalSnapshotUpdated;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommandCenterController extends Controller
{
    public function __construct(
        private readonly OperationalTelemetryService $telemetry,
        private readonly OperationalIssueService $issues,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');

        $snapshot = $this->telemetry->overview();
        $snapshot['issues'] = $this->issues->list($request);
        $snapshot['intelligence'] = $this->issues->intelligence();

        return response()->json($snapshot);
    }

    public function security(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'security_view');
        return response()->json($this->telemetry->securitySnapshot(true));
    }

    public function queues(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        return response()->json($this->telemetry->queueSnapshot(true));
    }

    public function retryJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAbility($request, 'queue_retry');
        abort_unless(Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404, 'Job não encontrado.');

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit($request, 'queue.retry', 'failed_jobs', null, ['uuid' => $uuid], ['retried' => true]);
        $this->broadcastRefresh('queue.retry');

        return response()->json(['message' => 'Job reenviado para processamento.', 'uuid' => $uuid]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        if (! Schema::hasTable('admin_incidents')) {
            return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0]);
        }

        $query = DB::table('admin_incidents as i')
            ->leftJoin('applications as a', 'a.id', '=', 'i.application_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')
            ->select('i.*', 'a.name as application_name', 'u.email as assignee_email');
        if ($request->filled('status')) $query->where('i.status', $request->string('status')->toString());
        if ($request->filled('severity')) $query->where('i.severity', $request->string('severity')->toString());

        return response()->json($query->orderByDesc('i.created_at')->paginate(50));
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'incident_manage');
        abort_unless(Schema::hasTable('admin_incidents'), 503, 'O armazenamento de incidentes ainda não foi provisionado.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', 'max:80'],
            'context' => ['nullable', 'array'],
        ]);

        $id = DB::table('admin_incidents')->insertGetId([
            'public_id' => 'INC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8)),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'],
            'status' => 'open',
            'source' => $data['source'] ?? 'manual',
            'application_id' => $data['application_id'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $request->user()?->id,
            'context' => isset($data['context']) ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $incident = DB::table('admin_incidents')->find($id);
        $this->audit($request, 'incident.created', 'admin_incidents', $id, null, (array) $incident);
        $this->broadcastRefresh('incident.created');

        return response()->json(['incident' => $incident], 201);
    }

    public function updateIncident(Request $request, int $incident): JsonResponse
    {
        $this->authorizeAbility($request, 'incident_manage');
        abort_unless(Schema::hasTable('admin_incidents'), 404);
        $before = DB::table('admin_incidents')->find($incident);
        abort_unless($before, 404, 'Incidente não encontrado.');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'investigating', 'resolved'])],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:5000'],
        ]);
        $patch = array_filter($data, static fn ($value) => $value !== null);
        if (($patch['status'] ?? null) === 'acknowledged') $patch['acknowledged_at'] = now();
        if (($patch['status'] ?? null) === 'resolved') $patch['resolved_at'] = now();
        $patch['updated_at'] = now();

        DB::table('admin_incidents')->where('id', $incident)->update($patch);
        $after = DB::table('admin_incidents')->find($incident);
        $this->audit($request, 'incident.updated', 'admin_incidents', $incident, (array) $before, (array) $after);
        $this->broadcastRefresh('incident.updated');

        return response()->json(['incident' => $after]);
    }

    public function application(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        return response()->json($this->telemetry->applicationDetails($application));
    }

    public function globalSearch(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:160']]);
        $q = trim($data['q']);
        $groups = [];

        $groups['users'] = $this->searchTable('users', $q,
            ['id', 'first_name', 'last_name', 'user_name', 'email', 'cpf', 'phone', 'created_at'],
            ['first_name', 'last_name', 'user_name', 'email', 'cpf', 'phone']);
        $groups['applications'] = $this->searchTable('applications', $q,
            ['id', 'name', 'slug', 'url', 'version', 'is_active'], ['name', 'slug', 'url']);
        $groups['establishments'] = $this->searchTable('establishments', $q,
            ['id', 'name', 'fantasy', 'slug', 'cnpj', 'city', 'uf', 'app_id'], ['name', 'fantasy', 'slug', 'cnpj']);
        $groups['items'] = $this->searchTable('items', $q,
            ['id', 'name', 'slug', 'sku', 'price', 'app_id', 'entity_name', 'entity_id', 'category'], ['name', 'slug', 'sku', 'category']);
        $groups['events'] = $this->searchTable('events', $q,
            ['id', 'name', 'title', 'slug', 'status', 'app_id', 'start_at', 'starts_at'], ['name', 'title', 'slug']);
        $groups['orders'] = $this->searchTable('orders', $q,
            ['id', 'public_id', 'reference', 'status', 'total', 'total_amount', 'app_id', 'user_id', 'created_at'], ['public_id', 'reference', 'status']);
        $groups['payments'] = $this->searchTable('payments', $q,
            ['id', 'public_id', 'provider_payment_id', 'external_id', 'status', 'amount', 'app_id', 'user_id', 'created_at'], ['public_id', 'provider_payment_id', 'external_id', 'status']);

        $groups = array_filter($groups, fn ($rows) => count($rows) > 0);
        return response()->json([
            'query' => $q,
            'groups' => $groups,
            'total' => collect($groups)->sum(fn ($rows) => count($rows)),
        ]);
    }

    public function issues(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        return response()->json($this->issues->list($request));
    }

    public function intelligence(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        return response()->json($this->issues->intelligence());
    }

    public function issue(Request $request, int $issue): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        return response()->json($this->issues->find($issue));
    }

    public function updateIssue(Request $request, int $issue): JsonResponse
    {
        $this->authorizeAbility($request, 'incident_manage');
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'severity' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'string', 'max:4'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $before = Schema::hasTable('operational_issues') ? DB::table('operational_issues')->find($issue) : null;
        $after = $this->issues->update($issue, $data, $request->user()?->id);
        $this->audit($request, 'operational_issue.updated', 'operational_issues', $issue, $before ? (array) $before : null, (array) $after);
        $this->broadcastRefresh('operational_issue.updated');

        return response()->json(['issue' => $after]);
    }

    public function createIssueIncident(Request $request, int $issue): JsonResponse
    {
        $this->authorizeAbility($request, 'incident_manage');
        $incident = $this->issues->createIncident($issue, $request->user()?->id);
        $this->audit($request, 'operational_issue.incident_created', 'operational_issues', $issue, null, ['incident_id' => $incident->id]);
        $this->broadcastRefresh('operational_issue.incident_created');

        return response()->json(['incident' => $incident], 201);
    }

    public function issueIntelligence(Request $request, int $issue): JsonResponse
    {
        $this->authorizeAbility($request, 'operations_view');
        $payload = $this->issues->find($issue);
        return response()->json($payload['intelligence'] ?? []);
    }

    public function repairPlan(Request $request, int $issue): JsonResponse
    {
        $this->authorizeAbility($request, 'repair_plan_manage');
        $payload = $this->issues->prepareRepairPlan($issue);
        $this->audit($request, 'operational_issue.repair_plan', 'operational_issues', $issue, null, $payload);
        $this->broadcastRefresh('operational_issue.repair_plan');

        return response()->json($payload, 201);
    }

    private function searchTable(string $table, string $q, array $selectCandidates, array $searchCandidates): array
    {
        if (! Schema::hasTable($table)) return [];
        $columns = Schema::getColumnListing($table);
        $select = array_values(array_intersect($selectCandidates, $columns));
        $search = array_values(array_intersect($searchCandidates, $columns));
        if (! $select || (! $search && ! in_array('id', $columns, true))) return [];

        $like = '%' . $q . '%';
        $query = DB::table($table)->select($select)->where(function ($builder) use ($search, $like, $q, $columns) {
            foreach ($search as $column) {
                $builder->orWhere($column, 'like', $like);
            }
            if (ctype_digit($q) && in_array('id', $columns, true)) {
                $builder->orWhere('id', (int) $q);
            }
        });

        return $query->limit(12)->get()->map(fn ($row) => (array) $row)->all();
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        $user = $request->user();
        $allowed = $user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission($ability) ||
            $user->hasPermission('ecosystem_manage')
        );
        abort_unless($allowed, 403, 'Usuário sem permissão para esta operação do Mission Control.');
    }

    private function audit(Request $request, string $action, string $entityType, ?int $entityId, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }

    private function broadcastRefresh(string $reason): void
    {
        try {
            event(new OperationalSnapshotUpdated($reason));
        } catch (\Throwable) {
            // Realtime is an optimization; the Admin Center retains polling fallback.
        }
    }
}
