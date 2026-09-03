<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CommandCenterController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $now = now();
        $applicationColumns = Schema::hasTable('applications') ? Schema::getColumnListing('applications') : [];
        $columns = array_values(array_intersect(['id', 'name', 'slug', 'url', 'logo', 'version', 'is_active'], $applicationColumns));
        $apps = Schema::hasTable('applications') ? Application::query()->orderBy('name')->get($columns ?: ['id']) : collect();

        $interactionColumns = Schema::hasTable('interactions') ? Schema::getColumnListing('interactions') : [];
        $hasAppId = in_array('app_id', $interactionColumns, true);
        $hasOutcome = in_array('outcome', $interactionColumns, true);
        $hasDuration = in_array('duration_ms', $interactionColumns, true);

        $applications = $apps->map(function ($app) use ($now, $hasAppId, $hasOutcome, $hasDuration) {
            $base = Schema::hasTable('interactions') && $hasAppId ? DB::table('interactions')->where('app_id', $app->id) : null;
            $lastActivity = $base ? (clone $base)->max('created_at') : null;
            $requests24h = $base ? (clone $base)->where('created_at', '>=', $now->copy()->subDay())->count() : 0;
            $errors24h = $base && $hasOutcome ? (clone $base)->where('created_at', '>=', $now->copy()->subDay())->where('outcome', 'error')->count() : 0;
            $latency = $base && $hasDuration ? (clone $base)->where('created_at', '>=', $now->copy()->subHour())->whereNotNull('duration_ms')->avg('duration_ms') : null;
            $probe = Schema::hasTable('admin_service_probes') ? DB::table('admin_service_probes')->where('application_id', $app->id)->orderByDesc('checked_at')->first() : null;
            $active = property_exists($app, 'is_active') || isset($app->is_active) ? (bool) $app->is_active : true;
            $status = ! $active ? 'disabled' : ($probe?->status ?: ($lastActivity && strtotime((string) $lastActivity) >= $now->copy()->subDay()->timestamp ? 'operational' : 'unknown'));
            $errorRate = $requests24h > 0 ? round(($errors24h / $requests24h) * 100, 2) : 0;
            if ($status === 'operational' && $errorRate >= 10) $status = 'degraded';

            return [
                'id' => $app->id,
                'name' => $app->name ?? ('Aplicação #' . $app->id),
                'slug' => $app->slug ?? null,
                'url' => $app->url ?? null,
                'logo' => $app->logo ?? null,
                'version' => $app->version ?? null,
                'status' => $status,
                'last_activity_at' => $lastActivity,
                'requests_24h' => $requests24h,
                'errors_24h' => $errors24h,
                'error_rate_24h' => $errorRate,
                'avg_latency_ms_1h' => $latency !== null ? (int) round((float) $latency) : null,
                'probe_http_status' => $probe?->http_status,
                'probe_latency_ms' => $probe?->latency_ms,
                'last_probe_at' => $probe?->checked_at,
            ];
        });

        $queues = $this->queueSnapshot();
        $runtime = $this->runtimeSnapshot();
        $security = $this->securitySnapshot();
        $incidents = $this->openIncidents();
        $score = 100;
        $score -= min($applications->whereIn('status', ['down', 'degraded'])->count() * 12, 36);
        $score -= min(((int) ($queues['failed'] ?? 0)) * 4, 20);
        $score -= min(((int) ($security['critical_events_24h'] ?? 0)) * 3, 18);
        $score -= min($incidents->where('severity', 'critical')->count() * 8, 24);
        if (($runtime['scheduler']['status'] ?? 'unknown') === 'failed') $score -= 15;
        $score = max(0, $score);

        return response()->json([
            'score' => $score,
            'status' => $score >= 90 ? 'healthy' : ($score >= 70 ? 'attention' : 'critical'),
            'applications' => $applications->values(),
            'queues' => $queues,
            'runtime' => $runtime,
            'security' => $security,
            'incidents' => $incidents->values(),
            'summary' => [
                'applications' => $applications->count(),
                'operational' => $applications->where('status', 'operational')->count(),
                'degraded' => $applications->where('status', 'degraded')->count(),
                'down' => $applications->where('status', 'down')->count(),
                'unknown' => $applications->where('status', 'unknown')->count(),
                'open_incidents' => $incidents->count(),
                'critical_incidents' => $incidents->where('severity', 'critical')->count(),
            ],
            'generated_at' => $now->toIso8601String(),
        ]);
    }

    public function security(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        return response()->json($this->securitySnapshot(true));
    }

    public function queues(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $snapshot = $this->queueSnapshot();
        $snapshot['failed_rows'] = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get(['uuid', 'connection', 'queue', 'exception', 'failed_at']) : [];
        return response()->json($snapshot);
    }

    public function retryJob(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_unless(Schema::hasTable('failed_jobs') && DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404, 'Job não encontrado.');
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit($request, 'queue.retry', 'failed_jobs', null, ['uuid' => $uuid], ['retried' => true]);
        return response()->json(['message' => 'Job reenviado para processamento.', 'uuid' => $uuid]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        if (! Schema::hasTable('admin_incidents')) return response()->json(['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0]);
        $query = DB::table('admin_incidents as i')->leftJoin('applications as a', 'a.id', '=', 'i.application_id')->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')->select('i.*', 'a.name as application_name', 'u.email as assignee_email');
        if ($request->filled('status')) $query->where('i.status', $request->string('status')->toString());
        if ($request->filled('severity')) $query->where('i.severity', $request->string('severity')->toString());
        return response()->json($query->orderByDesc('i.created_at')->paginate(50));
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
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
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'severity' => $data['severity'], 'status' => 'open',
            'source' => $data['source'] ?? 'manual', 'application_id' => $data['application_id'] ?? null, 'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $request->user()?->id, 'context' => isset($data['context']) ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $incident = DB::table('admin_incidents')->find($id);
        $this->audit($request, 'incident.created', 'admin_incidents', $id, null, (array) $incident);
        return response()->json(['incident' => $incident], 201);
    }

    public function updateIncident(Request $request, int $incident): JsonResponse
    {
        $this->authorizeAccess($request);
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
        return response()->json(['incident' => $after]);
    }

    public function application(Request $request, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $hasInteractions = Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'app_id');
        $activity = $hasInteractions ? DB::table('interactions')->where('app_id', $application->id) : null;
        return response()->json([
            'application' => $application,
            'metrics' => [
                'users' => Schema::hasTable('application_user') ? DB::table('application_user')->where('application_id', $application->id)->count() : 0,
                'establishments' => Schema::hasTable('application_establishment') ? DB::table('application_establishment')->where('application_id', $application->id)->count() : 0,
                'items' => Schema::hasTable('items') && Schema::hasColumn('items', 'app_id') ? DB::table('items')->where('app_id', $application->id)->count() : 0,
                'interactions_24h' => $activity ? (clone $activity)->where('created_at', '>=', now()->subDay())->count() : 0,
                'interactions_30d' => $activity ? (clone $activity)->where('created_at', '>=', now()->subDays(30))->count() : 0,
                'last_activity_at' => $activity ? (clone $activity)->max('created_at') : null,
            ],
            'probes' => Schema::hasTable('admin_service_probes') ? DB::table('admin_service_probes')->where('application_id', $application->id)->orderByDesc('checked_at')->limit(96)->get() : [],
            'recent_activity' => $activity ? (clone $activity)->orderByDesc('id')->limit(30)->get() : [],
            'incidents' => Schema::hasTable('admin_incidents') ? DB::table('admin_incidents')->where('application_id', $application->id)->orderByDesc('id')->limit(30)->get() : [],
        ]);
    }

    public function globalSearch(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:160']]);
        $q = trim($data['q']); $like = "%{$q}%"; $groups = [];
        if (Schema::hasTable('users')) {
            $cols = array_values(array_intersect(['id', 'first_name', 'last_name', 'user_name', 'email', 'created_at'], Schema::getColumnListing('users')));
            $groups['users'] = DB::table('users')->select($cols)->where(function ($query) use ($like, $q) {
                foreach (['email', 'user_name', 'first_name', 'last_name'] as $column) if (Schema::hasColumn('users', $column)) $query->orWhere($column, 'like', $like);
                if (ctype_digit($q)) $query->orWhere('id', (int) $q);
            })->limit(12)->get();
        }
        if (Schema::hasTable('applications')) {
            $cols = array_values(array_intersect(['id', 'name', 'slug', 'url', 'version', 'is_active'], Schema::getColumnListing('applications')));
            $groups['applications'] = DB::table('applications')->select($cols)->where(function ($query) use ($like) {
                $query->where('name', 'like', $like); if (Schema::hasColumn('applications', 'slug')) $query->orWhere('slug', 'like', $like);
            })->limit(12)->get();
        }
        if (Schema::hasTable('establishments')) {
            $cols = array_values(array_intersect(['id', 'name', 'fantasy', 'slug', 'city', 'uf', 'app_id'], Schema::getColumnListing('establishments')));
            $groups['establishments'] = DB::table('establishments')->select($cols)->where(function ($query) use ($like) {
                foreach (['name', 'fantasy', 'slug', 'cnpj'] as $column) if (Schema::hasColumn('establishments', $column)) $query->orWhere($column, 'like', $like);
            })->limit(12)->get();
        }
        if (Schema::hasTable('items')) {
            $cols = array_values(array_intersect(['id', 'name', 'slug', 'price', 'app_id', 'entity_name', 'entity_id', 'category'], Schema::getColumnListing('items')));
            $groups['items'] = DB::table('items')->select($cols)->where(function ($query) use ($like) {
                foreach (['name', 'slug', 'sku', 'category'] as $column) if (Schema::hasColumn('items', $column)) $query->orWhere($column, 'like', $like);
            })->limit(12)->get();
        }
        return response()->json(['query' => $q, 'groups' => $groups, 'total' => collect($groups)->sum(static fn ($rows) => count($rows))]);
    }

    public function issues(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        return response()->json(['data' => [], 'summary' => ['open' => 0, 'critical' => 0, 'p0' => 0, 'p1' => 0, 'regressions' => 0, 'resolved' => 0], 'filters' => ['categories' => [], 'domains' => []]]);
    }

    public function intelligence(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $security = $this->securitySnapshot(); $activeAlerts = (int) ($security['critical_events_24h'] ?? 0);
        return response()->json(['summary' => ['active_alerts' => $activeAlerts, 'critical_alerts' => $activeAlerts, 'deployments_24h' => 0, 'repair_plans' => 0], 'alerts' => [], 'deployments' => [], 'slos' => []]);
    }

    public function issue(Request $request, int $issue): JsonResponse { $this->authorizeAccess($request); abort(404, 'Problema operacional não encontrado.'); }
    public function updateIssue(Request $request, int $issue): JsonResponse { $this->authorizeAccess($request); abort(404, 'Problema operacional não encontrado.'); }
    public function createIssueIncident(Request $request, int $issue): JsonResponse { $this->authorizeAccess($request); abort(404, 'Problema operacional não encontrado.'); }
    public function issueIntelligence(Request $request, int $issue): JsonResponse { $this->authorizeAccess($request); abort(404, 'Problema operacional não encontrado.'); }
    public function repairPlan(Request $request, int $issue): JsonResponse { $this->authorizeAccess($request); abort(404, 'Problema operacional não encontrado.'); }

    private function queueSnapshot(): array
    {
        $queued = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        return ['queued' => $queued, 'failed' => $failed, 'oldest_available_at' => $oldest ? date(DATE_ATOM, (int) $oldest) : null, 'status' => $failed > 0 ? 'attention' : 'healthy'];
    }

    private function runtimeSnapshot(): array
    {
        $scheduler = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service', 'scheduler')->first() : null;
        $backup = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service', 'backup')->first() : null;
        $last = $scheduler?->last_seen_at;
        $schedulerStatus = $scheduler?->status ?: ($last && strtotime((string) $last) >= now()->subMinutes(3)->timestamp ? 'healthy' : 'unknown');
        $databaseConnected = true;
        try { DB::connection()->getPdo(); } catch (\Throwable) { $databaseConnected = false; }
        return [
            'scheduler' => ['status' => $schedulerStatus, 'last_seen_at' => $last],
            'backup' => ['status' => $backup?->status ?: 'unknown', 'last_success_at' => $backup?->last_seen_at, 'meta' => $backup?->meta ? json_decode($backup->meta, true) : null],
            'database' => ['driver' => DB::connection()->getDriverName(), 'connected' => $databaseConnected],
            'php' => PHP_VERSION, 'laravel' => app()->version(), 'environment' => app()->environment(),
        ];
    }

    private function securitySnapshot(bool $detail = false): array
    {
        $empty = ['critical_events_24h' => 0, 'suspicious_24h' => 0, 'denied_24h' => 0, 'errors_24h' => 0, 'events' => []];
        if (! Schema::hasTable('interactions')) return $empty;
        $columns = Schema::getColumnListing('interactions');
        $base = DB::table('interactions')->where('created_at', '>=', now()->subDay());
        $denied = in_array('outcome', $columns, true) ? (clone $base)->where('outcome', 'denied')->count() : 0;
        $errors = in_array('outcome', $columns, true) ? (clone $base)->where('outcome', 'error')->count() : 0;
        $suspicious = in_array('severity', $columns, true) ? (clone $base)->where('severity', 'suspicious')->count() : 0;
        $critical = in_array('severity', $columns, true) ? (clone $base)->where('severity', 'critical')->count() : 0;
        $payload = ['critical_events_24h' => $critical + $suspicious, 'suspicious_24h' => $suspicious, 'denied_24h' => $denied, 'errors_24h' => $errors, 'events' => []];
        if ($detail) {
            $query = (clone $base)->orderByDesc('id')->limit(100);
            if (in_array('severity', $columns, true)) $query->whereIn('severity', ['attention', 'suspicious', 'critical']);
            elseif (in_array('outcome', $columns, true)) $query->whereIn('outcome', ['denied', 'error']);
            $payload['events'] = $query->get();
        }
        return $payload;
    }

    private function openIncidents()
    {
        if (! Schema::hasTable('admin_incidents')) return collect();
        return DB::table('admin_incidents')->whereIn('status', ['open', 'acknowledged', 'investigating'])->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")->orderByDesc('created_at')->limit(20)->get();
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('ecosystem_manage') || $user->hasPermission('application_manage') || $user->hasPermission('user_management') || $user->hasPermission('permission_management')), 403, 'Usuário sem permissão para acessar o Mission Control.');
    }

    private function audit(Request $request, string $action, string $entityType, ?int $entityId, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        EcosystemAuditLog::create(['user_id' => $request->user()?->id, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'before' => $before, 'after' => $after, 'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 1000, '')]);
    }
}
