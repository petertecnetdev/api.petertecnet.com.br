<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalIssueService
{
    private const ACTIVE_STATUSES = ['new', 'acknowledged', 'investigating', 'fixed', 'monitoring'];

    public function list(Request $request): array
    {
        if (! Schema::hasTable('operational_issues')) {
            return $this->emptyList();
        }

        $query = DB::table('operational_issues as oi')
            ->leftJoin('applications as a', 'a.id', '=', 'oi.application_id')
            ->select('oi.*', 'a.name as application_name', 'a.slug as application_slug', 'a.version as application_version');

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $status = $request->string('status')->toString();
            if ($status === 'active') {
                $query->whereIn('oi.status', self::ACTIVE_STATUSES);
            } else {
                $query->where('oi.status', $status);
            }
        }
        if ($request->filled('priority') && $request->string('priority')->toString() !== 'all') {
            $query->where('oi.priority', $request->string('priority')->toString());
        }
        if ($request->filled('category') && $request->string('category')->toString() !== 'all') {
            $query->where('oi.category', $request->string('category')->toString());
        }
        if ($request->filled('domain') && $request->string('domain')->toString() !== 'all') {
            $query->where('oi.domain', $request->string('domain')->toString());
        }

        $rows = $query
            ->orderByRaw("CASE oi.priority WHEN 'P0' THEN 0 WHEN 'P1' THEN 1 WHEN 'P2' THEN 2 ELSE 3 END")
            ->orderByDesc('oi.last_seen_at')
            ->limit(250)
            ->get()
            ->map(fn ($row) => $this->decorateIssue($row));

        $summaryBase = DB::table('operational_issues');
        $open = (clone $summaryBase)->whereIn('status', self::ACTIVE_STATUSES)->count();
        $resolved = (clone $summaryBase)->where('status', 'resolved')->count();
        $critical = (clone $summaryBase)->whereIn('status', self::ACTIVE_STATUSES)->where('severity', 'critical')->count();
        $p0 = (clone $summaryBase)->whereIn('status', self::ACTIVE_STATUSES)->where('priority', 'P0')->count();
        $p1 = (clone $summaryBase)->whereIn('status', self::ACTIVE_STATUSES)->where('priority', 'P1')->count();
        $regressions = (clone $summaryBase)->whereIn('status', self::ACTIVE_STATUSES)->where('regression_count', '>', 0)->count();

        return [
            'data' => $rows->values(),
            'summary' => compact('open', 'critical', 'p0', 'p1', 'regressions', 'resolved'),
            'filters' => [
                'categories' => DB::table('operational_issues')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
                'domains' => DB::table('operational_issues')->whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain')->values(),
            ],
        ];
    }

    public function find(int $id): array
    {
        abort_unless(Schema::hasTable('operational_issues'), 404, 'Problema operacional não encontrado.');
        $issue = DB::table('operational_issues')->find($id);
        abort_unless($issue, 404, 'Problema operacional não encontrado.');

        $occurrences = Schema::hasTable('operational_issue_occurrences')
            ? DB::table('operational_issue_occurrences as o')
                ->leftJoin('applications as a', 'a.id', '=', 'o.application_id')
                ->where('o.issue_id', $id)
                ->orderByDesc('o.occurred_at')
                ->limit(100)
                ->get(['o.*', 'a.name as application_name', 'a.slug as application_slug'])
            : collect();

        $history = Schema::hasTable('operational_issue_history')
            ? DB::table('operational_issue_history as h')
                ->leftJoin('users as u', 'u.id', '=', 'h.actor_id')
                ->where('h.issue_id', $id)
                ->orderByDesc('h.created_at')
                ->limit(100)
                ->get(['h.*', 'u.email as actor_email'])
            : collect();

        return [
            'issue' => $this->decorateIssue($issue),
            'occurrences' => $occurrences,
            'history' => $history,
            'intelligence' => $this->issueIntelligence($issue),
        ];
    }

    public function update(int $id, array $patch, ?int $actorId = null): object
    {
        abort_unless(Schema::hasTable('operational_issues'), 404, 'Problema operacional não encontrado.');
        $before = DB::table('operational_issues')->find($id);
        abort_unless($before, 404, 'Problema operacional não encontrado.');

        $allowed = array_intersect_key($patch, array_flip(['status', 'severity', 'priority']));
        if (isset($allowed['status']) && ! in_array($allowed['status'], [...self::ACTIVE_STATUSES, 'resolved', 'ignored', 'expected'], true)) {
            abort(422, 'Status operacional inválido.');
        }
        if (isset($allowed['severity']) && ! in_array($allowed['severity'], ['info', 'warning', 'critical'], true)) {
            abort(422, 'Severidade inválida.');
        }
        if (isset($allowed['priority']) && ! in_array($allowed['priority'], ['P0', 'P1', 'P2', 'P3'], true)) {
            abort(422, 'Prioridade inválida.');
        }

        if (($allowed['status'] ?? null) === 'acknowledged') {
            $allowed['acknowledged_at'] = now();
        }
        if (($allowed['status'] ?? null) === 'resolved') {
            $allowed['resolved_at'] = now();
        }
        if (isset($allowed['status']) && in_array($allowed['status'], self::ACTIVE_STATUSES, true)) {
            $allowed['resolved_at'] = null;
        }
        $allowed['updated_at'] = now();

        DB::table('operational_issues')->where('id', $id)->update($allowed);
        $after = DB::table('operational_issues')->find($id);

        if (isset($allowed['status']) && $allowed['status'] !== $before->status) {
            $this->history($id, $before->status, $allowed['status'], $actorId, $patch['note'] ?? null);
        }

        return $after;
    }

    public function createIncident(int $id, ?int $userId = null): object
    {
        abort_unless(Schema::hasTable('operational_issues') && Schema::hasTable('admin_incidents'), 503, 'Infraestrutura de incidentes indisponível.');
        $issue = DB::table('operational_issues')->find($id);
        abort_unless($issue, 404, 'Problema operacional não encontrado.');

        if (Schema::hasColumn('admin_incidents', 'issue_id')) {
            $existing = DB::table('admin_incidents')
                ->where('issue_id', $id)
                ->whereIn('status', ['open', 'acknowledged', 'investigating'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $payload = [
            'public_id' => 'INC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8)),
            'title' => $issue->title,
            'description' => $issue->description ?: $issue->latest_message,
            'severity' => $issue->severity,
            'status' => 'open',
            'source' => 'operational_issue',
            'application_id' => $issue->application_id,
            'created_by' => $userId,
            'context' => json_encode(['issue_id' => $id, 'fingerprint' => $issue->fingerprint], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('admin_incidents', 'issue_id')) {
            $payload['issue_id'] = $id;
        }

        $incidentId = DB::table('admin_incidents')->insertGetId($payload);
        return DB::table('admin_incidents')->find($incidentId);
    }

    public function prepareRepairPlan(int $id): array
    {
        abort_unless(Schema::hasTable('operational_issues'), 404, 'Problema operacional não encontrado.');
        $issue = DB::table('operational_issues')->find($id);
        abort_unless($issue, 404, 'Problema operacional não encontrado.');

        $plan = [
            'mode' => 'supervised',
            'generated_at' => now()->toIso8601String(),
            'fingerprint' => $issue->fingerprint,
            'probable_cause' => $this->probableCause($issue),
            'steps' => $this->runbookSteps($issue),
            'validation_gates' => [
                'Confirmar causa por telemetria e reprodução controlada',
                'Executar testes automatizados do domínio afetado',
                'Validar migrations e compatibilidade retroativa',
                'Passar CI e revisão antes de qualquer deploy',
                'Executar health checks após deploy e manter janela de observação',
            ],
            'rollback' => [
                'required' => false,
                'strategy' => 'Manter a versão anterior disponível e só reverter com aprovação manual caso os indicadores piorem.',
            ],
        ];

        DB::table('operational_issues')->where('id', $id)->update([
            'repair_plan' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return ['repair_plan' => $plan];
    }

    public function intelligence(): array
    {
        $issues = Schema::hasTable('operational_issues')
            ? DB::table('operational_issues')->whereIn('status', self::ACTIVE_STATUSES)->orderByDesc('impact_score')->limit(20)->get()
            : collect();

        $alerts = $issues->map(fn ($issue) => [
            'id' => $issue->id,
            'fingerprint' => $issue->fingerprint,
            'level' => $issue->priority === 'P0' ? 'critical' : ($issue->priority === 'P1' ? 'warning' : 'info'),
            'reason' => $issue->title,
        ])->values();

        $deployments = $this->recentDeployments();
        $slos = $this->currentSlos();
        $repairPlans = Schema::hasTable('operational_issues')
            ? DB::table('operational_issues')->whereNotNull('repair_plan')->whereIn('status', self::ACTIVE_STATUSES)->count()
            : 0;

        return [
            'summary' => [
                'active_alerts' => $issues->count(),
                'critical_alerts' => $issues->whereIn('priority', ['P0', 'P1'])->count(),
                'deployments_24h' => count($deployments),
                'repair_plans' => $repairPlans,
            ],
            'alerts' => $alerts,
            'deployments' => $deployments,
            'slos' => $slos,
        ];
    }

    public function evaluateFromCurrentState(): void
    {
        if (! Schema::hasTable('operational_issues')) {
            return;
        }

        $activeFingerprints = [];
        $now = now();

        if (Schema::hasTable('applications') && Schema::hasTable('admin_service_probes')) {
            $latestTimes = DB::table('admin_service_probes')
                ->select('application_id', DB::raw('MAX(checked_at) as checked_at'))
                ->groupBy('application_id');

            $apps = DB::table('applications as a')
                ->leftJoinSub($latestTimes, 'lp', fn ($join) => $join->on('lp.application_id', '=', 'a.id'))
                ->leftJoin('admin_service_probes as p', function ($join) {
                    $join->on('p.application_id', '=', 'a.id')->on('p.checked_at', '=', 'lp.checked_at');
                })
                ->where(function ($query) {
                    if (Schema::hasColumn('applications', 'is_active')) {
                        $query->where('a.is_active', true)->orWhereNull('a.is_active');
                    }
                })
                ->get(['a.id', 'a.name', 'a.slug', 'a.version', 'p.status', 'p.http_status', 'p.latency_ms', 'p.error', 'p.checked_at']);

            foreach ($apps as $app) {
                $fresh = $app->checked_at && strtotime((string) $app->checked_at) >= $now->copy()->subMinutes(3)->timestamp;
                if (! $fresh) {
                    $fingerprint = 'telemetry:application:' . $app->id . ':stale';
                    $activeFingerprints[] = $fingerprint;
                    $this->touchIssue($fingerprint, [
                        'application_id' => $app->id,
                        'category' => 'operational',
                        'domain' => 'observability',
                        'title' => 'Telemetria da aplicação está desatualizada',
                        'description' => 'O Mission Control não recebeu um health probe recente para esta aplicação.',
                        'severity' => 'warning',
                        'priority' => 'P2',
                        'impact_score' => 45,
                        'latest_message' => 'Último probe fora da janela de 3 minutos.',
                        'source_version' => $app->version,
                        'context' => ['last_probe_at' => $app->checked_at, 'application' => $app->slug],
                    ]);
                    continue;
                }

                if (in_array($app->status, ['down', 'degraded'], true)) {
                    $fingerprint = 'availability:application:' . $app->id . ':' . $app->status;
                    $activeFingerprints[] = $fingerprint;
                    $this->touchIssue($fingerprint, [
                        'application_id' => $app->id,
                        'category' => $app->status === 'down' ? 'dependency' : 'operational',
                        'domain' => 'availability',
                        'title' => $app->status === 'down' ? 'Aplicação indisponível' : 'Aplicação degradada',
                        'description' => 'Health check automático detectou degradação de disponibilidade.',
                        'severity' => $app->status === 'down' ? 'critical' : 'warning',
                        'priority' => $app->status === 'down' ? 'P0' : 'P1',
                        'impact_score' => $app->status === 'down' ? 95 : 70,
                        'latest_http_status' => $app->http_status,
                        'latest_message' => $app->error ?: ('Latência: ' . ($app->latency_ms ?? '—') . ' ms'),
                        'source_version' => $app->version,
                        'context' => ['latency_ms' => $app->latency_ms, 'checked_at' => $app->checked_at, 'application' => $app->slug],
                    ]);
                }
            }
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        if ($failedJobs > 0) {
            $fingerprint = 'runtime:queue:failed_jobs';
            $activeFingerprints[] = $fingerprint;
            $this->touchIssue($fingerprint, [
                'category' => 'operational', 'domain' => 'queue', 'title' => 'Jobs falhando na fila',
                'description' => 'Existem jobs na failed_jobs que exigem investigação.', 'severity' => $failedJobs >= 10 ? 'critical' : 'warning',
                'priority' => $failedJobs >= 10 ? 'P1' : 'P2', 'impact_score' => min(90, 35 + ($failedJobs * 5)),
                'latest_message' => $failedJobs . ' job(s) falho(s).', 'context' => ['failed_jobs' => $failedJobs],
            ]);
        }

        $scheduler = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service', 'scheduler')->first() : null;
        if (! $scheduler || ! $scheduler->last_seen_at || strtotime((string) $scheduler->last_seen_at) < $now->copy()->subMinutes(3)->timestamp) {
            $fingerprint = 'runtime:scheduler:stale';
            $activeFingerprints[] = $fingerprint;
            $this->touchIssue($fingerprint, [
                'category' => 'operational', 'domain' => 'scheduler', 'title' => 'Scheduler sem heartbeat recente',
                'description' => 'O scheduler pode estar parado ou atrasado.', 'severity' => 'critical', 'priority' => 'P1', 'impact_score' => 80,
                'latest_message' => 'Heartbeat do scheduler excedeu 3 minutos.', 'context' => ['last_seen_at' => $scheduler?->last_seen_at],
            ]);
        }

        $backup = Schema::hasTable('admin_runtime_heartbeats') ? DB::table('admin_runtime_heartbeats')->where('service', 'backup')->first() : null;
        if (! $backup || ! $backup->last_seen_at || strtotime((string) $backup->last_seen_at) < $now->copy()->subHours(26)->timestamp) {
            $fingerprint = 'runtime:backup:stale';
            $activeFingerprints[] = $fingerprint;
            $this->touchIssue($fingerprint, [
                'category' => 'operational', 'domain' => 'backup', 'title' => 'Backup sem confirmação recente',
                'description' => 'Nenhum backup válido foi confirmado dentro da janela operacional.', 'severity' => 'warning', 'priority' => 'P1', 'impact_score' => 75,
                'latest_message' => 'Último backup excedeu 26 horas ou nunca foi confirmado.', 'context' => ['last_success_at' => $backup?->last_seen_at],
            ]);
        }

        if (Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'outcome') && Schema::hasColumn('interactions', 'created_at')) {
            $errors15m = DB::table('interactions')->where('created_at', '>=', $now->copy()->subMinutes(15))->where('outcome', 'error')->count();
            if ($errors15m >= 20) {
                $fingerprint = 'telemetry:errors:spike';
                $activeFingerprints[] = $fingerprint;
                $this->touchIssue($fingerprint, [
                    'category' => 'application_error', 'domain' => 'platform', 'title' => 'Pico de erros detectado',
                    'description' => 'A taxa absoluta de erros cresceu acima do limite operacional em 15 minutos.', 'severity' => $errors15m >= 100 ? 'critical' : 'warning',
                    'priority' => $errors15m >= 100 ? 'P0' : 'P1', 'impact_score' => min(100, 60 + intdiv($errors15m, 5)),
                    'latest_message' => $errors15m . ' erros em 15 minutos.', 'context' => ['errors_15m' => $errors15m],
                ]);
            }
        }

        foreach ($this->currentSlos() as $slo) {
            if (! ($slo['breached'] ?? false) || empty($slo['application_id'])) {
                continue;
            }
            foreach ($slo['breaches'] as $breach) {
                $fingerprint = 'slo:application:' . $slo['application_id'] . ':' . $breach;
                $activeFingerprints[] = $fingerprint;
                $this->touchIssue($fingerprint, [
                    'application_id' => $slo['application_id'], 'category' => 'operational', 'domain' => 'slo',
                    'title' => 'SLO violado: ' . str_replace('_', ' ', $breach), 'description' => 'Indicadores recentes ultrapassaram a meta de confiabilidade configurada.',
                    'severity' => $breach === 'availability' ? 'critical' : 'warning', 'priority' => $breach === 'availability' ? 'P1' : 'P2',
                    'impact_score' => $breach === 'availability' ? 78 : 58, 'latest_message' => 'Violação de SLO detectada automaticamente.',
                    'context' => ['slo' => $slo],
                ]);
            }
        }

        $this->resolveRecoveredIssues($activeFingerprints);
        $this->pruneOperationalHistory();
    }

    public function currentSlos(): array
    {
        if (! Schema::hasTable('applications')) {
            return [];
        }

        $default = Schema::hasTable('operational_slos')
            ? DB::table('operational_slos')->whereNull('application_id')->where('domain', 'platform')->where('enabled', true)->first()
            : null;

        $configs = Schema::hasTable('operational_slos')
            ? DB::table('operational_slos')->whereNotNull('application_id')->where('enabled', true)->get()->keyBy('application_id')
            : collect();

        $apps = DB::table('applications')->select(array_values(array_intersect(['id', 'name', 'slug', 'is_active'], Schema::getColumnListing('applications'))))->get();
        $maxWindow = max(60, (int) ($configs->max('window_minutes') ?: ($default->window_minutes ?? 60)));
        $probeRows = Schema::hasTable('admin_service_probes')
            ? DB::table('admin_service_probes')->where('checked_at', '>=', now()->subMinutes($maxWindow))->orderBy('checked_at')->get()
            : collect();
        $probesByApp = $probeRows->groupBy('application_id');

        $errorsByApp = collect();
        $requestsByApp = collect();
        if (Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'app_id') && Schema::hasColumn('interactions', 'created_at')) {
            $hasOutcome = Schema::hasColumn('interactions', 'outcome');
            $rows = DB::table('interactions')
                ->where('created_at', '>=', now()->subMinutes($maxWindow))
                ->select('app_id', DB::raw('COUNT(*) as request_count'))
                ->when($hasOutcome, fn ($query) => $query->selectRaw("SUM(CASE WHEN outcome = 'error' THEN 1 ELSE 0 END) as error_count"))
                ->groupBy('app_id')
                ->get();
            $requestsByApp = $rows->pluck('request_count', 'app_id');
            $errorsByApp = $rows->pluck('error_count', 'app_id');
        }

        return $apps->map(function ($app) use ($configs, $default, $probesByApp, $requestsByApp, $errorsByApp) {
            $config = $configs->get($app->id) ?: $default;
            $window = (int) ($config->window_minutes ?? 60);
            $cutoff = now()->subMinutes($window)->timestamp;
            $probes = ($probesByApp->get($app->id) ?: collect())->filter(fn ($probe) => strtotime((string) $probe->checked_at) >= $cutoff)->values();
            $probeCount = $probes->count();
            $successCount = $probes->whereIn('status', ['operational', 'healthy'])->count();
            $availability = $probeCount > 0 ? round(($successCount / $probeCount) * 100, 3) : null;
            $latencies = $probes->pluck('latency_ms')->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value)->sort()->values();
            $p95 = $this->percentile($latencies, 0.95);
            $requests = (int) ($requestsByApp->get($app->id) ?? 0);
            $errors = (int) ($errorsByApp->get($app->id) ?? 0);
            $errorRate = $requests > 0 ? round(($errors / $requests) * 100, 3) : 0.0;
            $targetAvailability = (float) ($config->availability_target ?? 99.0);
            $maxError = (float) ($config->max_error_rate ?? 5.0);
            $maxP95 = (int) ($config->p95_latency_ms ?? 1500);
            $breaches = [];
            if ($availability !== null && $availability < $targetAvailability) $breaches[] = 'availability';
            if ($requests >= 10 && $errorRate > $maxError) $breaches[] = 'error_rate';
            if ($p95 !== null && $p95 > $maxP95) $breaches[] = 'p95_latency';

            return [
                'id' => $config->id ?? ('default-' . $app->id),
                'application_id' => $app->id,
                'application_name' => $app->name ?? ('Aplicação #' . $app->id),
                'application_slug' => $app->slug ?? null,
                'domain' => $config->domain ?? 'platform',
                'availability_target' => $targetAvailability,
                'max_error_rate' => $maxError,
                'p95_latency_ms' => $maxP95,
                'window_minutes' => $window,
                'enabled' => true,
                'actual' => ['availability' => $availability, 'error_rate' => $errorRate, 'p95_latency_ms' => $p95, 'probe_count' => $probeCount, 'request_count' => $requests],
                'breached' => count($breaches) > 0,
                'breaches' => $breaches,
            ];
        })->values()->all();
    }

    private function touchIssue(string $fingerprint, array $data): object
    {
        $existing = DB::table('operational_issues')->where('fingerprint', $fingerprint)->first();
        $context = $data['context'] ?? [];
        unset($data['context']);

        if (! $existing) {
            $id = DB::table('operational_issues')->insertGetId(array_merge([
                'public_id' => 'OPS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8)),
                'fingerprint' => $fingerprint,
                'status' => 'new',
                'source' => 'monitor',
                'occurrence_count' => 1,
                'regression_count' => 0,
                'applications_affected_count' => ! empty($data['application_id']) ? 1 : 0,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ], $data));
            $issue = DB::table('operational_issues')->find($id);
            $this->history($id, null, 'new', null, 'Detectado automaticamente pelo monitor operacional.');
        } else {
            $regression = in_array($existing->status, ['resolved', 'ignored'], true);
            $patch = array_merge($data, [
                'last_seen_at' => now(),
                'occurrence_count' => ((int) $existing->occurrence_count) + 1,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            if ($regression) {
                $patch['status'] = 'new';
                $patch['resolved_at'] = null;
                $patch['regression_count'] = ((int) $existing->regression_count) + 1;
            }
            DB::table('operational_issues')->where('id', $existing->id)->update($patch);
            $issue = DB::table('operational_issues')->find($existing->id);
            if ($regression) {
                $this->history($existing->id, $existing->status, 'new', null, 'Regressão detectada automaticamente.');
            }
        }

        if (Schema::hasTable('operational_issue_occurrences')) {
            DB::table('operational_issue_occurrences')->insert([
                'issue_id' => $issue->id,
                'application_id' => $issue->application_id,
                'method' => $issue->latest_method,
                'route' => $issue->latest_route,
                'http_status' => $issue->latest_http_status,
                'error_code' => $issue->latest_error_code,
                'message' => $issue->latest_message,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $issue;
    }

    private function resolveRecoveredIssues(array $activeFingerprints): void
    {
        $query = DB::table('operational_issues')
            ->where('source', 'monitor')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('last_seen_at', '<', now()->subMinutes(5));
        if (count($activeFingerprints)) {
            $query->whereNotIn('fingerprint', array_values(array_unique($activeFingerprints)));
        }

        foreach ($query->get() as $issue) {
            DB::table('operational_issues')->where('id', $issue->id)->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
            $this->history($issue->id, $issue->status, 'resolved', null, 'Condição recuperada automaticamente e mantida fora da janela de alerta.');
        }
    }

    private function history(int $issueId, ?string $from, string $to, ?int $actorId, ?string $note): void
    {
        if (! Schema::hasTable('operational_issue_history')) {
            return;
        }
        DB::table('operational_issue_history')->insert([
            'issue_id' => $issueId,
            'actor_id' => $actorId,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note ? Str::limit($note, 1000, '') : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decorateIssue(object $row): array
    {
        $current24 = Schema::hasTable('operational_issue_occurrences')
            ? DB::table('operational_issue_occurrences')->where('issue_id', $row->id)->where('occurred_at', '>=', now()->subDay())->count()
            : 0;
        $previous24 = Schema::hasTable('operational_issue_occurrences')
            ? DB::table('operational_issue_occurrences')->where('issue_id', $row->id)->whereBetween('occurred_at', [now()->subDays(2), now()->subDay()])->count()
            : 0;
        $direction = $previous24 === 0 ? ($current24 > 0 ? 'new' : 'stable') : ($current24 > $previous24 ? 'up' : ($current24 < $previous24 ? 'down' : 'stable'));
        $change = $previous24 > 0 ? round((($current24 - $previous24) / $previous24) * 100, 1) : null;

        $application = null;
        if ($row->application_id && Schema::hasTable('applications')) {
            $application = DB::table('applications')->where('id', $row->application_id)->first(['id', 'name', 'slug', 'version']);
        }

        return array_merge((array) $row, [
            'applications' => $application ? [(array) $application] : [],
            'trend' => ['direction' => $direction, 'current_24h' => $current24, 'previous_24h' => $previous24, 'change_percent' => $change],
        ]);
    }

    private function issueIntelligence(object $issue): array
    {
        $context = $issue->context ? json_decode($issue->context, true) : [];
        $slo = collect($this->currentSlos())->firstWhere('application_id', $issue->application_id);
        $recent = Schema::hasTable('operational_issue_occurrences')
            ? DB::table('operational_issue_occurrences')->where('issue_id', $issue->id)->where('occurred_at', '>=', now()->subHour())->count()
            : 0;
        $baseline = Schema::hasTable('operational_issue_occurrences')
            ? DB::table('operational_issue_occurrences')->where('issue_id', $issue->id)->whereBetween('occurred_at', [now()->subDays(7), now()->subHour()])->count() / (24 * 7)
            : 0;
        $multiplier = $baseline > 0 ? round($recent / $baseline, 2) : null;

        return [
            'probable_cause' => $this->probableCause($issue),
            'deploy_correlation' => ['matched' => false, 'strong_temporal_correlation' => false],
            'slo' => $slo ? [
                'available' => true,
                'breached' => $slo['breached'],
                'breaches' => $slo['breaches'],
                'actual' => $slo['actual'],
                'target' => ['availability' => $slo['availability_target'], 'max_error_rate' => $slo['max_error_rate'], 'p95_latency_ms' => $slo['p95_latency_ms']],
            ] : ['available' => false, 'breached' => false, 'breaches' => []],
            'anomaly' => ['detected' => $multiplier !== null && $multiplier >= 3, 'recent_1h' => $recent, 'baseline_hourly' => round($baseline, 2), 'multiplier' => $multiplier],
            'financial_impact' => ['applicable' => false, 'failed_payments' => 0, 'potentially_affected_amount' => 0],
            'rollback' => ['recommended' => false, 'reason' => 'Sem evidência suficiente para recomendar rollback automático. A decisão permanece supervisionada.'],
            'correlations' => [],
            'journeys' => [],
            'dependency_map' => ['affected_domain' => $issue->domain, 'nodes' => [['id' => $issue->domain, 'state' => 'affected']]],
            'runbooks' => [[
                'slug' => 'generic-' . $issue->domain,
                'title' => 'Runbook de diagnóstico ' . $issue->domain,
                'risk_level' => 'baixo',
                'description' => 'Procedimento genérico e reutilizável para validar a condição antes de qualquer alteração.',
                'steps' => $this->runbookSteps($issue),
            ]],
            'correction_assistant' => [
                'mode' => 'supervised',
                'validation_gates' => ['reproduzir/confirmar', 'testes automatizados', 'CI', 'health check pós-deploy', 'janela de observação'],
            ],
            'context' => $context,
        ];
    }

    private function probableCause(object $issue): array
    {
        $summary = match ($issue->domain) {
            'availability' => 'A aplicação deixou de responder corretamente ao probe externo ou respondeu em estado degradado.',
            'observability' => 'A coleta de telemetria não está chegando dentro da janela esperada; verifique scheduler, conectividade e processo de probe.',
            'queue' => 'Um ou mais jobs excederam tentativas e foram enviados para failed_jobs.',
            'scheduler' => 'O heartbeat do scheduler não foi atualizado; o cron ou schedule:run pode estar interrompido.',
            'backup' => 'O backup mais recente não foi encontrado ou está fora da janela de segurança.',
            'slo' => 'Métricas recentes de confiabilidade ultrapassaram o orçamento configurado.',
            default => 'A telemetria agregada detectou um padrão operacional fora do comportamento esperado.',
        };

        return [
            'summary' => $summary,
            'confidence' => in_array($issue->domain, ['queue', 'scheduler', 'backup', 'availability'], true) ? 90 : 72,
            'evidence' => array_values(array_filter([$issue->latest_message, $issue->latest_http_status ? 'HTTP ' . $issue->latest_http_status : null, 'fingerprint ' . $issue->fingerprint])),
            'candidate_files' => [],
        ];
    }

    private function runbookSteps(object $issue): array
    {
        return match ($issue->domain) {
            'queue' => ['Identificar jobs falhos e exceções recorrentes.', 'Confirmar dependências e payloads.', 'Corrigir a causa antes de reprocessar.', 'Reprocessar somente os jobs afetados e observar a fila.'],
            'scheduler' => ['Verificar cron e processo schedule:run.', 'Executar schedule:list e confirmar tarefas devidas.', 'Validar permissões de storage/cache.', 'Restabelecer heartbeat e observar por pelo menos 5 minutos.'],
            'backup' => ['Confirmar artefato de backup mais recente.', 'Validar integridade e tamanho do arquivo.', 'Executar backup supervisionado se necessário.', 'Registrar heartbeat somente após confirmação do artefato.'],
            'availability' => ['Confirmar DNS/TLS e resposta HTTP.', 'Verificar aplicação e dependências internas.', 'Comparar com último deploy.', 'Executar smoke tests antes e depois da correção.'],
            default => ['Confirmar a anomalia com múltiplas fontes.', 'Isolar aplicação/domínio afetado.', 'Aplicar correção mínima e reutilizável.', 'Executar testes, CI e health checks antes de encerrar.'],
        };
    }

    private function recentDeployments(): array
    {
        if (! Schema::hasTable('deployments')) {
            return [];
        }
        $columns = Schema::getColumnListing('deployments');
        $select = array_values(array_intersect(['id', 'application_id', 'commit_sha', 'version', 'deployed_at', 'created_at'], $columns));
        if (! $select) {
            return [];
        }
        $timeColumn = in_array('deployed_at', $columns, true) ? 'deployed_at' : 'created_at';
        return DB::table('deployments as d')
            ->leftJoin('applications as a', 'a.id', '=', 'd.application_id')
            ->where('d.' . $timeColumn, '>=', now()->subDay())
            ->orderByDesc('d.' . $timeColumn)
            ->limit(20)
            ->get(array_merge(array_map(fn ($column) => 'd.' . $column, $select), ['a.name as application_name']))
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function percentile(Collection $values, float $percentile): ?int
    {
        if ($values->isEmpty()) return null;
        $index = (int) ceil($percentile * $values->count()) - 1;
        return (int) $values->get(max(0, min($index, $values->count() - 1)));
    }

    private function pruneOperationalHistory(): void
    {
        if (Schema::hasTable('operational_issue_occurrences')) {
            DB::table('operational_issue_occurrences')->where('occurred_at', '<', now()->subDays(30))->delete();
        }
    }

    private function emptyList(): array
    {
        return [
            'data' => [],
            'summary' => ['open' => 0, 'critical' => 0, 'p0' => 0, 'p1' => 0, 'regressions' => 0, 'resolved' => 0],
            'filters' => ['categories' => [], 'domains' => []],
        ];
    }
}
