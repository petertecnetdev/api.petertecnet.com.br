<?php

namespace App\Http\Controllers\Admin;

use App\Events\EcosystemUpdated;
use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use App\Services\Operations\OperationalIssueClassifier;
use App\Services\Operations\OperationalIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OperationalIssueController extends Controller
{
    private const ACTIVE_STATUSES = ['new', 'acknowledged', 'investigating', 'fixed', 'monitoring'];
    private const STATUSES = ['new', 'acknowledged', 'investigating', 'fixed', 'monitoring', 'resolved', 'ignored', 'expected'];

    public function __construct(
        private readonly OperationalIssueClassifier $classifier,
        private readonly OperationalIssueService $issues,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        if (! $this->issues->available()) return response()->json($this->emptyPayload());

        $perPage = max(10, min((int) $request->integer('per_page', 50), 100));
        $query = DB::table('operational_issues as oi')
            ->leftJoin('applications as a', 'a.id', '=', 'oi.latest_application_id')
            ->leftJoin('users as u', 'u.id', '=', 'oi.assigned_to')
            ->select('oi.*', 'a.name as latest_application_name', 'a.slug as latest_application_slug', 'u.email as assignee_email');

        if ($request->filled('status')) {
            $status = (string) $request->string('status');
            if ($status === 'active') $query->whereIn('oi.status', self::ACTIVE_STATUSES);
            elseif (in_array($status, self::STATUSES, true)) $query->where('oi.status', $status);
        } else {
            $query->whereIn('oi.status', self::ACTIVE_STATUSES);
        }

        if ($request->filled('severity')) $query->where('oi.severity', (string) $request->string('severity'));
        if ($request->filled('category')) $query->where('oi.category', (string) $request->string('category'));
        if ($request->filled('domain')) $query->where('oi.domain', (string) $request->string('domain'));
        if ($request->filled('priority')) {
            match (strtoupper((string) $request->string('priority'))) {
                'P0' => $query->where('oi.impact_score', '>=', 80),
                'P1' => $query->whereBetween('oi.impact_score', [60, 79]),
                'P2' => $query->whereBetween('oi.impact_score', [40, 59]),
                'P3' => $query->where('oi.impact_score', '<', 40),
                default => null,
            };
        }
        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->string('q')).'%';
            $query->where(function ($builder) use ($needle) {
                $builder->where('oi.fingerprint', 'like', $needle)
                    ->orWhere('oi.title', 'like', $needle)
                    ->orWhere('oi.latest_message', 'like', $needle)
                    ->orWhere('oi.latest_error_code', 'like', $needle)
                    ->orWhere('oi.latest_route', 'like', $needle);
            });
        }

        $page = $query->orderByDesc('oi.impact_score')->orderByDesc('oi.last_seen_at')->paginate($perPage);
        $rows = collect($page->items());
        $decorated = $this->decorate($rows);

        return response()->json([
            'data' => $decorated,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => $this->issues->summary(),
            'filters' => [
                'categories' => DB::table('operational_issues')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
                'domains' => DB::table('operational_issues')->whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain')->values(),
                'statuses' => self::STATUSES,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $request, int $issue): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless($this->issues->available(), 404, 'Central de problemas operacionais ainda não está disponível.');

        $row = DB::table('operational_issues as oi')
            ->leftJoin('applications as a', 'a.id', '=', 'oi.latest_application_id')
            ->leftJoin('users as u', 'u.id', '=', 'oi.assigned_to')
            ->select('oi.*', 'a.name as latest_application_name', 'a.slug as latest_application_slug', 'u.email as assignee_email')
            ->where('oi.id', $issue)->first();
        abort_unless($row, 404, 'Problema operacional não encontrado.');

        $issueData = $this->decorate(collect([$row]))->first();
        $occurrences = DB::table('operational_issue_occurrences as o')
            ->leftJoin('applications as a', 'a.id', '=', 'o.application_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.operational_issue_id', $issue)
            ->orderByDesc('o.occurred_at')
            ->limit(100)
            ->get([
                'o.*', 'a.name as application_name', 'a.slug as application_slug', 'u.email as user_email',
            ])
            ->map(fn ($item) => $this->decodeJsonFields($item, ['metadata']))
            ->values();

        $history = DB::table('operational_issue_transitions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.actor_id')
            ->where('t.operational_issue_id', $issue)
            ->orderByDesc('t.created_at')
            ->limit(100)
            ->get(['t.*', 'u.email as actor_email'])
            ->map(fn ($item) => $this->decodeJsonFields($item, ['metadata']))
            ->values();

        return response()->json([
            'issue' => $issueData,
            'occurrences' => $occurrences,
            'history' => $history,
        ]);
    }

    public function update(Request $request, int $issue): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($this->issues->available(), 404);
        $before = DB::table('operational_issues')->find($issue);
        abort_unless($before, 404, 'Problema operacional não encontrado.');

        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category' => ['nullable', 'string', 'max:48'],
            'domain' => ['nullable', 'string', 'max:80'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $patch = [];
        foreach (['category', 'domain', 'assigned_to'] as $field) {
            if (array_key_exists($field, $data)) $patch[$field] = $data[$field];
        }

        $newStatus = $data['status'] ?? null;
        if ($newStatus && $newStatus !== $before->status) {
            $patch['status'] = $newStatus;
            $patch += match ($newStatus) {
                'acknowledged' => ['acknowledged_at' => now()],
                'fixed' => ['fixed_at' => now()],
                'monitoring' => ['monitoring_at' => now()],
                'resolved' => ['resolved_at' => now()],
                'ignored', 'expected' => ['ignored_at' => now()],
                'new' => ['resolved_at' => null, 'fixed_at' => null, 'monitoring_at' => null, 'ignored_at' => null],
                default => [],
            };
        }

        $patch['updated_at'] = now();
        DB::table('operational_issues')->where('id', $issue)->update($patch);

        if ($newStatus && $newStatus !== $before->status) {
            DB::table('operational_issue_transitions')->insert([
                'operational_issue_id' => $issue,
                'from_status' => $before->status,
                'to_status' => $newStatus,
                'actor_id' => $request->user()?->id,
                'note' => $data['note'] ?? null,
                'metadata' => json_encode(['manual' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        }

        $after = DB::table('operational_issues')->find($issue);
        $this->audit($request, 'operational_issue.updated', $issue, (array) $before, (array) $after);
        broadcast(new EcosystemUpdated(['command', 'issues', 'security'], 'operational_issue.updated'));

        return response()->json(['issue' => $after]);
    }

    public function createIncident(Request $request, int $issue): JsonResponse
    {
        $this->authorizeIncident($request);
        abort_unless($this->issues->available() && Schema::hasTable('admin_incidents'), 404);
        $operationalIssue = DB::table('operational_issues')->find($issue);
        abort_unless($operationalIssue, 404, 'Problema operacional não encontrado.');

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $severity = $operationalIssue->severity === 'critical' ? 'critical' : 'warning';
        $context = [
            'operational_issue_id' => $operationalIssue->id,
            'fingerprint' => $operationalIssue->fingerprint,
            'category' => $operationalIssue->category,
            'domain' => $operationalIssue->domain,
            'impact_score' => $operationalIssue->impact_score,
            'latest_interaction_id' => $operationalIssue->latest_interaction_id,
            'source_version' => $operationalIssue->source_version,
            'source_commit' => $operationalIssue->source_commit,
        ];

        $incidentId = DB::table('admin_incidents')->insertGetId([
            'public_id' => 'INC-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
            'title' => '['.$operationalIssue->fingerprint.'] '.Str::limit($operationalIssue->title, 140, ''),
            'description' => $data['description'] ?? $operationalIssue->latest_message,
            'severity' => $severity,
            'status' => 'open',
            'source' => 'operational_issue',
            'application_id' => $operationalIssue->latest_application_id,
            'assigned_to' => $data['assigned_to'] ?? $operationalIssue->assigned_to,
            'created_by' => $request->user()?->id,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incident = DB::table('admin_incidents')->find($incidentId);
        $this->audit($request, 'operational_issue.incident_created', $issue, null, (array) $incident);
        broadcast(new EcosystemUpdated(['command', 'issues', 'incidents'], 'operational_issue.incident_created'));

        return response()->json(['incident' => $incident], 201);
    }

    private function decorate(Collection $rows): Collection
    {
        if ($rows->isEmpty()) return $rows;
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->values();

        $applications = DB::table('operational_issue_occurrences as o')
            ->join('applications as a', 'a.id', '=', 'o.application_id')
            ->whereIn('o.operational_issue_id', $ids)
            ->select('o.operational_issue_id', 'a.id', 'a.name', 'a.slug', 'a.version')
            ->distinct()->get()->groupBy('operational_issue_id');

        $previousStart = now()->subHours(48);
        $currentStart = now()->subHours(24);
        $trendRows = DB::table('operational_issue_occurrences')
            ->whereIn('operational_issue_id', $ids)
            ->where('occurred_at', '>=', $previousStart)
            ->get(['operational_issue_id', 'occurred_at'])
            ->groupBy('operational_issue_id');

        return $rows->map(function ($row) use ($applications, $trendRows, $currentStart) {
            $items = $trendRows[(int) $row->id] ?? collect();
            $current = $items->filter(fn ($item) => $item->occurred_at && strtotime($item->occurred_at) >= $currentStart->timestamp)->count();
            $previous = max(0, $items->count() - $current);
            $trend = $this->trend($current, $previous);
            $appRows = collect($applications[(int) $row->id] ?? [])->map(fn ($app) => (array) $app)->values();
            $payload = (array) $row;
            $payload['priority'] = $this->classifier->priority((int) $row->impact_score);
            $payload['trend'] = $trend;
            $payload['applications'] = $appRows;
            $payload['context'] = $this->decodeJson($row->context ?? null);
            return $payload;
        })->values();
    }

    private function trend(int $current, int $previous): array
    {
        if ($previous === 0 && $current > 0) return ['direction' => 'new', 'current_24h' => $current, 'previous_24h' => 0, 'change_percent' => null];
        $change = $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : 0;
        $direction = $change > 10 ? 'up' : ($change < -10 ? 'down' : 'stable');
        return ['direction' => $direction, 'current_24h' => $current, 'previous_24h' => $previous, 'change_percent' => $change];
    }

    private function decodeJsonFields(object $item, array $fields): array
    {
        $payload = (array) $item;
        foreach ($fields as $field) $payload[$field] = $this->decodeJson($payload[$field] ?? null);
        return $payload;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) return $value;
        if (! is_string($value) || $value === '') return null;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function emptyPayload(): array
    {
        return [
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            'summary' => $this->issues->summary(),
            'filters' => ['categories' => [], 'domains' => [], 'statuses' => self::STATUSES],
            'generated_at' => now()->toIso8601String(),
        ];
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

    private function authorizeIncident(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('incident_manage') || $user->hasPermission('ecosystem_manage')), 403);
    }

    private function audit(Request $request, string $action, int $issueId, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => 'operational_issues',
            'entity_id' => $issueId,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
