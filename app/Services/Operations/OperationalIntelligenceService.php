<?php

namespace App\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalIntelligenceService
{
    public function __construct(private readonly OperationalIssueClassifier $classifier)
    {
    }

    public function available(): bool
    {
        return Schema::hasTable('operational_deployments')
            && Schema::hasTable('operational_slo_definitions')
            && Schema::hasTable('operational_issue_correlations')
            && Schema::hasTable('operational_runbooks')
            && Schema::hasTable('operational_journey_definitions')
            && Schema::hasTable('operational_alerts')
            && Schema::hasTable('operational_repair_plans');
    }

    public function analyze(object|array $issue): array
    {
        $issue = (array) $issue;
        $issue['context'] = $this->decodeJson($issue['context'] ?? null) ?: [];
        $issue['priority'] = $issue['priority'] ?? $this->classifier->priority((int) ($issue['impact_score'] ?? 0));

        $deploy = $this->deployCorrelation($issue);
        $cause = $this->probableCause($issue);
        $slo = $this->sloSnapshot($issue);
        $anomaly = $this->anomaly($issue);
        $journeys = $this->journeyImpact($issue);
        $financial = $this->financialImpact($issue);
        $runbooks = $this->runbooks($issue);
        $correlations = $this->correlations($issue);
        $dependencyMap = $this->dependencyMap($issue);
        $alert = $this->alertPolicy($issue, $slo, $anomaly);
        $rollback = $this->rollbackRecommendation($issue, $deploy, $anomaly, $slo);
        $assistant = $this->correctionAssistant($issue, $cause, $runbooks, $rollback, $slo, $anomaly, $financial);

        $analysis = [
            'generated_at' => now()->toIso8601String(),
            'deploy_correlation' => $deploy,
            'probable_cause' => $cause,
            'slo' => $slo,
            'anomaly' => $anomaly,
            'journeys' => $journeys,
            'financial_impact' => $financial,
            'runbooks' => $runbooks,
            'correlations' => $correlations,
            'dependency_map' => $dependencyMap,
            'alert_policy' => $alert,
            'rollback' => $rollback,
            'correction_assistant' => $assistant,
        ];

        if (isset($issue['id'])) $this->persistDerivedState((int) $issue['id'], $analysis);

        return $analysis;
    }

    public function overview(): array
    {
        if (! $this->available()) {
            return ['active_alerts' => 0, 'critical_alerts' => 0, 'deployments_24h' => 0, 'repair_plans' => 0];
        }

        return [
            'active_alerts' => DB::table('operational_alerts')->where('status', 'open')->count(),
            'critical_alerts' => DB::table('operational_alerts')->where('status', 'open')->whereIn('level', ['critical', 'high'])->count(),
            'deployments_24h' => DB::table('operational_deployments')->where('deployed_at', '>=', now()->subDay())->count(),
            'repair_plans' => DB::table('operational_repair_plans')->whereIn('status', ['suggested', 'reviewing'])->count(),
        ];
    }

    public function recordDeployment(array $data): array
    {
        abort_unless(Schema::hasTable('operational_deployments'), 503, 'Registro de deploys ainda não está disponível.');

        $applicationId = isset($data['application_id']) && is_numeric($data['application_id']) ? (int) $data['application_id'] : null;
        $environment = (string) ($data['environment'] ?? 'production');
        $commit = $this->stringOrNull($data['commit_sha'] ?? null);
        $version = $this->stringOrNull($data['version'] ?? null);
        $deployedAt = $data['deployed_at'] ?? now();

        $existing = null;
        if ($commit) {
            $existing = DB::table('operational_deployments')
                ->where('application_id', $applicationId)
                ->where('environment', $environment)
                ->where('commit_sha', $commit)
                ->first();
        }
        if ($existing) return (array) $existing;

        $previous = DB::table('operational_deployments')
            ->where('application_id', $applicationId)
            ->where('environment', $environment)
            ->orderByDesc('deployed_at')
            ->first();

        $id = DB::table('operational_deployments')->insertGetId([
            'application_id' => $applicationId,
            'environment' => $environment,
            'version' => $version,
            'commit_sha' => $commit,
            'previous_commit_sha' => $this->stringOrNull($data['previous_commit_sha'] ?? null) ?: ($previous->commit_sha ?? null),
            'status' => (string) ($data['status'] ?? 'succeeded'),
            'source' => (string) ($data['source'] ?? 'manual'),
            'deployed_at' => $deployedAt,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) DB::table('operational_deployments')->find($id);
    }

    public function ensureDeploymentFromIssue(array $event): void
    {
        if (! Schema::hasTable('operational_deployments')) return;
        $commit = $this->firstNonEmpty([
            $this->nested($event, ['request_context', 'application_context', 'commit_sha']),
            $this->nested($event, ['request_context', 'application_context', 'release_sha']),
            $this->nested($event, ['request_context', 'application_context', 'deployment_sha']),
        ]);
        if (! $commit) return;

        $applicationId = $event['application']['id'] ?? null;
        $environment = $event['environment'] ?? 'production';
        if (DB::table('operational_deployments')->where('application_id', $applicationId)->where('environment', $environment)->where('commit_sha', $commit)->exists()) return;

        $this->recordDeployment([
            'application_id' => $applicationId,
            'environment' => $environment,
            'version' => $event['application']['version'] ?? null,
            'commit_sha' => $commit,
            'deployed_at' => $this->nested($event, ['request_context', 'application_context', 'deployed_at']) ?: ($event['occurred_at'] ?? now()),
            'status' => 'observed',
            'source' => 'telemetry',
            'metadata' => ['inferred_from_interaction' => $event['id'] ?? null],
        ]);
    }

    public function createRepairPlan(object|array $issue, ?int $actorId = null): array
    {
        abort_unless(Schema::hasTable('operational_repair_plans'), 503, 'Planos de correção ainda não estão disponíveis.');
        $issue = (array) $issue;
        $analysis = $this->analyze($issue);
        $assistant = $analysis['correction_assistant'];

        $id = DB::table('operational_repair_plans')->insertGetId([
            'operational_issue_id' => (int) $issue['id'],
            'status' => 'suggested',
            'risk_level' => $assistant['risk_level'],
            'diagnostic_bundle' => json_encode($assistant['diagnostic_bundle'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'candidate_files' => json_encode($assistant['candidate_files'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'recommended_steps' => json_encode($assistant['recommended_steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'validation_gates' => json_encode($assistant['validation_gates'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rollback_strategy' => $analysis['rollback']['strategy'] ?? 'Preparar rollback do commit antes de alterar produção.',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->decodeJsonFields((array) DB::table('operational_repair_plans')->find($id), [
            'diagnostic_bundle', 'candidate_files', 'recommended_steps', 'validation_gates',
        ]);
    }

    private function deployCorrelation(array $issue): array
    {
        $commit = $this->stringOrNull($issue['source_commit'] ?? null);
        $firstSeen = $issue['first_seen_at'] ?? null;
        $applicationId = isset($issue['latest_application_id']) ? (int) $issue['latest_application_id'] : null;

        if (! Schema::hasTable('operational_deployments')) {
            return ['matched' => false, 'commit_sha' => $commit, 'reason' => 'deployment_registry_unavailable'];
        }

        $query = DB::table('operational_deployments')->where('status', '!=', 'failed');
        if ($applicationId) $query->where('application_id', $applicationId);
        if ($commit) {
            $deployment = (clone $query)->where('commit_sha', $commit)->orderByDesc('deployed_at')->first();
        } elseif ($firstSeen) {
            $deployment = (clone $query)->where('deployed_at', '<=', $firstSeen)->orderByDesc('deployed_at')->first();
        } else {
            $deployment = null;
        }

        if (! $deployment) {
            return ['matched' => false, 'commit_sha' => $commit, 'reason' => $commit ? 'commit_not_registered' : 'no_nearby_deployment'];
        }

        $minutes = null;
        if ($firstSeen && $deployment->deployed_at) {
            try { $minutes = Carbon::parse($deployment->deployed_at)->diffInMinutes(Carbon::parse($firstSeen), false); } catch (\Throwable) { $minutes = null; }
        }

        $strong = $minutes !== null && $minutes >= 0 && $minutes <= 60;
        return [
            'matched' => true,
            'strong_temporal_correlation' => $strong,
            'deployment_id' => $deployment->id,
            'application_id' => $deployment->application_id,
            'environment' => $deployment->environment,
            'version' => $deployment->version,
            'commit_sha' => $deployment->commit_sha,
            'previous_commit_sha' => $deployment->previous_commit_sha,
            'deployed_at' => $deployment->deployed_at,
            'minutes_before_first_error' => $minutes,
            'source' => $deployment->source,
        ];
    }

    private function probableCause(array $issue): array
    {
        $category = (string) ($issue['category'] ?? 'operational');
        $domain = (string) ($issue['domain'] ?? 'platform');
        $context = is_array($issue['context'] ?? null) ? $issue['context'] : [];
        $technical = is_array($context['technical'] ?? null) ? $context['technical'] : [];
        $message = Str::lower((string) ($issue['latest_message'] ?? $issue['title'] ?? ''));
        $evidence = [];
        $confidence = 45;

        $summaries = [
            'database' => 'Falha provável na camada de persistência, consulta, constraint, lock ou conectividade do banco.',
            'dependency' => 'Falha provável em uma dependência externa ou na conectividade entre serviços.',
            'timeout' => 'A operação ultrapassou o tempo esperado; o gargalo pode estar em banco, fila ou dependência externa.',
            'authentication' => 'Falha na autenticação, sessão ou credencial do usuário.',
            'authorization' => 'Falha na autorização ou política de acesso ao recurso.',
            'validation' => 'A requisição não satisfez o contrato de validação esperado.',
            'business_rule' => 'Uma regra de negócio ou conflito de estado impediu a operação.',
            'server_exception' => 'Exceção interna do servidor durante a execução da requisição.',
            'application_error' => 'Erro da aplicação sem categoria mais específica identificada.',
            'security' => 'Evento suspeito que exige validação de segurança e contexto de acesso.',
        ];

        if (! empty($technical['exception_class'])) { $evidence[] = 'exception_class: '.$technical['exception_class']; $confidence += 20; }
        if (! empty($technical['file'])) { $evidence[] = 'exception_file: '.$technical['file']; $confidence += 15; }
        if (! empty($issue['latest_error_code'])) { $evidence[] = 'error_code: '.$issue['latest_error_code']; $confidence += 8; }
        if (! empty($issue['latest_route'])) { $evidence[] = 'route: '.$issue['latest_route']; $confidence += 5; }
        if (Str::contains($message, ['sqlstate', 'exception', 'timeout', 'refused'])) $confidence += 5;

        return [
            'summary' => $summaries[$category] ?? 'A causa ainda não é conclusiva; correlacione stack trace, rota, deploy e problemas relacionados.',
            'confidence' => min(95, $confidence),
            'category' => $category,
            'domain' => $domain,
            'evidence' => $evidence,
            'candidate_files' => $this->candidateFiles($issue, $technical),
        ];
    }

    private function candidateFiles(array $issue, array $technical): array
    {
        $files = [];
        if (! empty($technical['file'])) $files[] = ['path' => $technical['file'], 'reason' => 'Arquivo informado pela exceção', 'confidence' => 95];
        if (! empty($technical['route_action'])) $files[] = ['path' => $technical['route_action'], 'reason' => 'Ação responsável pela rota', 'confidence' => 85];

        $domain = (string) ($issue['domain'] ?? 'platform');
        $category = (string) ($issue['category'] ?? 'operational');
        $domainCandidates = [
            'payments' => ['app/Services/Payments/', 'app/Services/EcosystemPaymentLedgerService.php', 'app/Http/Controllers/PaymentController.php'],
            'commerce' => ['app/Services/Commerce/', 'app/Http/Controllers/CommerceController.php'],
            'scheduling' => ['app/Services/Scheduling/', 'app/Http/Controllers/AppointmentController.php'],
            'identity' => ['app/Http/Controllers/AuthController.php', 'app/Services/ApplicationContextService.php'],
            'catalog' => ['app/Http/Controllers/ItemController.php', 'app/Http/Controllers/EstablishmentController.php'],
            'events' => ['app/Http/Controllers/EventController.php', 'app/Services/Events/'],
            'runtime' => ['app/Console/', 'app/Jobs/', 'app/Services/HealthCheckService.php'],
            'notifications' => ['app/Services/AppNotificationService.php', 'app/Mail/'],
            'files' => ['app/Http/Controllers/MediaController.php', 'app/Services/Media/'],
        ];
        foreach ($domainCandidates[$domain] ?? ['app/Http/Controllers/', 'app/Services/'] as $path) {
            $files[] = ['path' => $path, 'reason' => 'Candidato pelo domínio '.$domain, 'confidence' => 55];
        }
        if ($category === 'database') $files[] = ['path' => 'database/migrations/', 'reason' => 'Falha classificada como banco de dados', 'confidence' => 65];

        return collect($files)->unique('path')->values()->all();
    }

    private function sloSnapshot(array $issue): array
    {
        if (! Schema::hasTable('interactions')) return ['available' => false, 'breached' => false, 'reason' => 'telemetry_unavailable'];
        $columns = Schema::getColumnListing('interactions');
        $domain = (string) ($issue['domain'] ?? 'platform');
        $applicationId = isset($issue['latest_application_id']) ? (int) $issue['latest_application_id'] : null;
        $definition = $this->sloDefinition($domain, $applicationId);
        $window = max(15, (int) ($definition['window_minutes'] ?? 60));
        $query = DB::table('interactions')->where('created_at', '>=', now()->subMinutes($window));
        if ($applicationId && in_array('app_id', $columns, true)) $query->where('app_id', $applicationId);
        $this->applyDomainFilter($query, $domain, $columns);

        $total = (clone $query)->count();
        $errors = in_array('outcome', $columns, true) ? (clone $query)->where('outcome', 'error')->count() : 0;
        $errorRate = $total > 0 ? round(($errors / $total) * 100, 3) : 0.0;
        $availability = $total > 0 ? round(100 - $errorRate, 3) : 100.0;
        $p95 = null;
        if (in_array('duration_ms', $columns, true)) {
            $durations = (clone $query)->whereNotNull('duration_ms')->orderBy('duration_ms')->limit(2000)->pluck('duration_ms')->map(fn ($value) => (int) $value)->values();
            if ($durations->isNotEmpty()) $p95 = $durations[(int) floor(($durations->count() - 1) * 0.95)];
        }

        $breaches = [];
        if ($availability < (float) $definition['availability_target']) $breaches[] = 'availability';
        if ($errorRate > (float) $definition['max_error_rate']) $breaches[] = 'error_rate';
        if ($p95 !== null && $p95 > (int) $definition['p95_latency_ms']) $breaches[] = 'p95_latency';

        return [
            'available' => true,
            'breached' => ! empty($breaches),
            'breaches' => $breaches,
            'window_minutes' => $window,
            'sample_requests' => $total,
            'actual' => ['availability' => $availability, 'error_rate' => $errorRate, 'p95_latency_ms' => $p95],
            'target' => [
                'availability' => (float) $definition['availability_target'],
                'max_error_rate' => (float) $definition['max_error_rate'],
                'p95_latency_ms' => (int) $definition['p95_latency_ms'],
            ],
        ];
    }

    private function sloDefinition(string $domain, ?int $applicationId): array
    {
        if (Schema::hasTable('operational_slo_definitions')) {
            if ($applicationId) {
                $specific = DB::table('operational_slo_definitions')->where('enabled', true)->where('application_id', $applicationId)->where('domain', $domain)->latest('id')->first();
                if ($specific) return (array) $specific;
            }
            $global = DB::table('operational_slo_definitions')->where('enabled', true)->whereNull('application_id')->where('domain', $domain)->latest('id')->first();
            if ($global) return (array) $global;
        }
        return ['availability_target' => 99.9, 'max_error_rate' => 1.0, 'p95_latency_ms' => 1500, 'window_minutes' => 60];
    }

    private function anomaly(array $issue): array
    {
        if (! Schema::hasTable('operational_issue_occurrences') || empty($issue['id'])) return ['detected' => false, 'reason' => 'occurrences_unavailable'];
        $id = (int) $issue['id'];
        $recent = DB::table('operational_issue_occurrences')->where('operational_issue_id', $id)->where('occurred_at', '>=', now()->subHour())->count();
        $baselineTotal = DB::table('operational_issue_occurrences')->where('operational_issue_id', $id)->whereBetween('occurred_at', [now()->subHours(25), now()->subHour()])->count();
        $baselineHourly = round($baselineTotal / 24, 2);
        $multiplier = $baselineHourly > 0 ? round($recent / $baselineHourly, 2) : ($recent > 0 ? null : 0);
        $threshold = max(5, (int) ceil($baselineHourly * 3));
        $detected = $recent >= $threshold;

        return [
            'detected' => $detected,
            'recent_1h' => $recent,
            'baseline_24h_total' => $baselineTotal,
            'baseline_hourly' => $baselineHourly,
            'multiplier' => $multiplier,
            'threshold' => $threshold,
            'reason' => $detected ? 'spike_above_dynamic_baseline' : 'within_expected_range',
        ];
    }

    private function journeyImpact(array $issue): array
    {
        if (! Schema::hasTable('operational_journey_definitions')) return [];
        $domain = (string) ($issue['domain'] ?? 'platform');
        return DB::table('operational_journey_definitions')->where('enabled', true)->get()->map(function ($row) use ($domain) {
            $steps = $this->decodeJson($row->steps) ?: [];
            $index = array_search($domain, $steps, true);
            if ($index === false) return null;
            return [
                'slug' => $row->slug,
                'name' => $row->name,
                'critical' => (bool) $row->critical,
                'affected_step' => $domain,
                'step_index' => $index,
                'steps' => $steps,
            ];
        })->filter()->values()->all();
    }

    private function financialImpact(array $issue): array
    {
        $domain = (string) ($issue['domain'] ?? 'platform');
        if (! in_array($domain, ['payments', 'commerce'], true) || ! Schema::hasTable('ecosystem_payments')) {
            return ['applicable' => false, 'failed_payments' => 0, 'potentially_affected_amount' => 0.0];
        }
        $columns = Schema::getColumnListing('ecosystem_payments');
        if (! in_array('created_at', $columns, true)) return ['applicable' => false, 'failed_payments' => 0, 'potentially_affected_amount' => 0.0];

        $from = $issue['first_seen_at'] ? Carbon::parse($issue['first_seen_at'])->subMinutes(15) : now()->subDay();
        $to = $issue['last_seen_at'] ? Carbon::parse($issue['last_seen_at'])->addMinutes(15) : now();
        $query = DB::table('ecosystem_payments')->whereBetween('created_at', [$from, $to]);
        $appSlugs = $this->affectedApplicationSlugs((int) ($issue['id'] ?? 0));
        if ($appSlugs && in_array('app_slug', $columns, true)) $query->whereIn('app_slug', $appSlugs);

        $failedStatuses = ['failed', 'rejected', 'cancelled', 'canceled', 'error', 'refused'];
        $failed = in_array('status', $columns, true) ? (clone $query)->whereIn('status', $failedStatuses) : clone $query;
        $count = $failed->count();
        $amount = in_array('gross_amount', $columns, true) ? (float) (clone $failed)->sum('gross_amount') : 0.0;

        return [
            'applicable' => true,
            'window_start' => $from->toIso8601String(),
            'window_end' => $to->toIso8601String(),
            'failed_payments' => $count,
            'potentially_affected_amount' => round($amount, 2),
            'currency' => 'BRL',
            'scope' => 'failed ecosystem payments temporally correlated with the issue',
        ];
    }

    private function runbooks(array $issue): array
    {
        if (! Schema::hasTable('operational_runbooks')) return [];
        $domain = (string) ($issue['domain'] ?? 'platform');
        $category = (string) ($issue['category'] ?? 'operational');
        return DB::table('operational_runbooks')->where('enabled', true)
            ->where(function ($query) use ($domain, $category) {
                $query->where('domain', $domain)->orWhere('category', $category);
            })->get()->map(fn ($row) => [
                'slug' => $row->slug, 'title' => $row->title, 'description' => $row->description,
                'risk_level' => $row->risk_level, 'steps' => $this->decodeJson($row->steps) ?: [],
            ])->values()->all();
    }

    private function correlations(array $issue): array
    {
        if (! Schema::hasTable('operational_issues') || empty($issue['id'])) return [];
        $id = (int) $issue['id'];
        $from = $issue['first_seen_at'] ? Carbon::parse($issue['first_seen_at'])->subMinutes(30) : now()->subDay();
        $to = $issue['last_seen_at'] ? Carbon::parse($issue['last_seen_at'])->addMinutes(30) : now();
        $others = DB::table('operational_issues')->where('id', '!=', $id)->where('last_seen_at', '>=', $from)->where('first_seen_at', '<=', $to)->orderByDesc('last_seen_at')->limit(100)->get();

        return $others->map(function ($other) use ($issue) {
            $score = 0; $reasons = [];
            if (! empty($issue['source_commit']) && $issue['source_commit'] === $other->source_commit) { $score += 35; $reasons[] = 'same_deploy_commit'; }
            if (($issue['domain'] ?? null) === $other->domain) { $score += 25; $reasons[] = 'same_domain'; }
            if (($issue['category'] ?? null) === $other->category) { $score += 10; $reasons[] = 'same_category'; }
            if (! empty($issue['latest_application_id']) && (int) $issue['latest_application_id'] === (int) $other->latest_application_id) { $score += 15; $reasons[] = 'same_application'; }
            try {
                $distance = abs(Carbon::parse($issue['first_seen_at'])->diffInMinutes(Carbon::parse($other->first_seen_at), false));
                if ($distance <= 10) { $score += 15; $reasons[] = 'started_within_10_minutes'; }
                elseif ($distance <= 30) { $score += 8; $reasons[] = 'started_within_30_minutes'; }
            } catch (\Throwable) {}
            if ($this->routeRoot($issue['latest_route'] ?? null) && $this->routeRoot($issue['latest_route'] ?? null) === $this->routeRoot($other->latest_route)) { $score += 8; $reasons[] = 'same_route_family'; }
            if ($score < 45) return null;
            return [
                'issue_id' => (int) $other->id, 'fingerprint' => $other->fingerprint, 'title' => $other->title,
                'status' => $other->status, 'impact_score' => (int) $other->impact_score,
                'priority' => $this->classifier->priority((int) $other->impact_score),
                'score' => min(100, $score), 'reasons' => $reasons,
            ];
        })->filter()->sortByDesc('score')->take(10)->values()->all();
    }

    private function dependencyMap(array $issue): array
    {
        $affected = (string) ($issue['domain'] ?? 'platform');
        $nodes = ['identity','establishments','catalog','events','scheduling','commerce','payments','fulfillment','notifications','files','runtime','platform'];
        $edges = [
            ['from'=>'identity','to'=>'establishments'], ['from'=>'identity','to'=>'commerce'],
            ['from'=>'establishments','to'=>'catalog'], ['from'=>'catalog','to'=>'commerce'], ['from'=>'catalog','to'=>'scheduling'],
            ['from'=>'events','to'=>'commerce'], ['from'=>'commerce','to'=>'payments'], ['from'=>'payments','to'=>'fulfillment'],
            ['from'=>'scheduling','to'=>'notifications'], ['from'=>'fulfillment','to'=>'notifications'],
            ['from'=>'platform','to'=>'runtime'], ['from'=>'platform','to'=>'files'],
        ];
        return [
            'affected_domain' => $affected,
            'nodes' => array_map(fn ($node) => ['id' => $node, 'state' => $node === $affected ? 'affected' : 'normal'], $nodes),
            'edges' => $edges,
        ];
    }

    private function alertPolicy(array $issue, array $slo, array $anomaly): array
    {
        if (in_array($issue['status'] ?? null, ['ignored', 'expected', 'resolved'], true)) return ['should_alert' => false, 'level' => 'none', 'reasons' => ['suppressed_by_issue_status']];
        $reasons = [];
        $priority = $issue['priority'] ?? $this->classifier->priority((int) ($issue['impact_score'] ?? 0));
        $level = 'none';
        if ($priority === 'P0') { $level = 'critical'; $reasons[] = 'priority_p0'; }
        elseif ($priority === 'P1') { $level = 'high'; $reasons[] = 'priority_p1'; }
        if ((int) ($issue['regression_count'] ?? 0) > 0) { if ($level === 'none') $level = 'high'; $reasons[] = 'regression'; }
        if ($anomaly['detected'] ?? false) { if ($level === 'none') $level = 'warning'; $reasons[] = 'anomaly_spike'; }
        if ($slo['breached'] ?? false) { if ($level === 'none') $level = 'warning'; $reasons[] = 'slo_breach'; }
        return ['should_alert' => $level !== 'none', 'level' => $level, 'reasons' => array_values(array_unique($reasons))];
    }

    private function rollbackRecommendation(array $issue, array $deploy, array $anomaly, array $slo): array
    {
        $priority = $issue['priority'] ?? $this->classifier->priority((int) ($issue['impact_score'] ?? 0));
        $strongDeploy = (bool) ($deploy['strong_temporal_correlation'] ?? false);
        $severe = in_array($priority, ['P0', 'P1'], true) || ($anomaly['detected'] ?? false) || ((int) ($issue['regression_count'] ?? 0) > 0);
        $recommended = $strongDeploy && $severe && ! empty($deploy['previous_commit_sha']);

        return [
            'recommended' => $recommended,
            'requires_manual_approval' => true,
            'target_commit' => $recommended ? $deploy['previous_commit_sha'] : null,
            'current_commit' => $deploy['commit_sha'] ?? ($issue['source_commit'] ?? null),
            'reason' => $recommended ? 'Problema relevante surgiu logo após um deploy e existe commit anterior conhecido.' : 'Sem evidência suficiente para recomendar rollback automático.',
            'strategy' => 'Reverter somente após confirmar causalidade, executar testes de regressão e manter caminho de retorno ao commit atual.',
            'slo_breached' => (bool) ($slo['breached'] ?? false),
        ];
    }

    private function correctionAssistant(array $issue, array $cause, array $runbooks, array $rollback, array $slo, array $anomaly, array $financial): array
    {
        $risk = in_array($issue['domain'] ?? null, ['payments', 'identity'], true) || ($issue['priority'] ?? null) === 'P0' ? 'high' : 'medium';
        $recommended = [];
        foreach ($runbooks as $runbook) foreach ($runbook['steps'] ?? [] as $step) $recommended[] = $step;
        if (! $recommended) $recommended = ['Reproduzir o problema com contexto equivalente.', 'Localizar a camada genérica responsável.', 'Criar correção mínima e reutilizável.', 'Executar testes e monitorar regressão.'];

        return [
            'mode' => 'supervised',
            'state' => 'ready_for_review',
            'risk_level' => $risk,
            'automatic_production_changes' => false,
            'diagnostic_bundle' => [
                'issue_id' => $issue['id'] ?? null,
                'fingerprint' => $issue['fingerprint'] ?? null,
                'priority' => $issue['priority'] ?? null,
                'impact_score' => (int) ($issue['impact_score'] ?? 0),
                'category' => $issue['category'] ?? null,
                'domain' => $issue['domain'] ?? null,
                'route' => trim((string) (($issue['latest_method'] ?? '').' '.($issue['latest_route'] ?? ''))),
                'http_status' => $issue['latest_http_status'] ?? null,
                'error_code' => $issue['latest_error_code'] ?? null,
                'message' => $issue['latest_message'] ?? null,
                'source_commit' => $issue['source_commit'] ?? null,
                'regressions' => (int) ($issue['regression_count'] ?? 0),
                'slo' => $slo,
                'anomaly' => $anomaly,
                'financial_impact' => $financial,
            ],
            'candidate_files' => $cause['candidate_files'],
            'recommended_steps' => array_values(array_unique($recommended)),
            'validation_gates' => [
                'Reproduzir o erro antes da alteração.',
                'Executar testes unitários e de integração direcionados.',
                'Executar suíte completa da API e auditoria de migrations.',
                'Confirmar que a solução pertence ao domínio genérico e não a um aplicativo específico.',
                'Passar CI/build das aplicações afetadas.',
                'Preparar rollback e monitorar a fingerprint após deploy.',
            ],
            'architecture_guards' => ['generic_domain_first', 'no_app_specific_backend_coupling', 'backward_compatibility', 'idempotency_when_applicable'],
            'rollback_recommendation' => $rollback,
        ];
    }

    private function persistDerivedState(int $issueId, array $analysis): void
    {
        if (Schema::hasTable('operational_issue_correlations')) {
            foreach ($analysis['correlations'] ?? [] as $correlation) {
                $a = min($issueId, (int) $correlation['issue_id']);
                $b = max($issueId, (int) $correlation['issue_id']);
                DB::table('operational_issue_correlations')->updateOrInsert(
                    ['operational_issue_id' => $a, 'related_issue_id' => $b],
                    ['score' => $correlation['score'], 'reasons' => json_encode($correlation['reasons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }

        if (Schema::hasTable('operational_alerts')) {
            $policy = $analysis['alert_policy'] ?? [];
            if ($policy['should_alert'] ?? false) {
                $reasonKey = implode('-', $policy['reasons'] ?? ['operational']);
                $key = 'issue-'.$issueId.'-'.substr(hash('sha256', $reasonKey), 0, 12);
                $existing = DB::table('operational_alerts')->where('alert_key', $key)->first();
                if ($existing) {
                    DB::table('operational_alerts')->where('id', $existing->id)->update([
                        'level' => $policy['level'], 'status' => 'open', 'reason' => implode(', ', $policy['reasons']),
                        'metadata' => json_encode(['slo' => $analysis['slo'], 'anomaly' => $analysis['anomaly']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'last_triggered_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('operational_alerts')->insert([
                        'operational_issue_id' => $issueId, 'alert_key' => $key, 'level' => $policy['level'], 'status' => 'open',
                        'reason' => implode(', ', $policy['reasons']),
                        'metadata' => json_encode(['slo' => $analysis['slo'], 'anomaly' => $analysis['anomaly']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'first_triggered_at' => now(), 'last_triggered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } else {
                DB::table('operational_alerts')->where('operational_issue_id', $issueId)->where('status', 'open')->update(['status' => 'resolved', 'updated_at' => now()]);
            }
        }
    }

    private function applyDomainFilter($query, string $domain, array $columns): void
    {
        if (! in_array('route', $columns, true)) return;
        $terms = $this->domainTerms($domain);
        if (! $terms) return;
        $query->where(function ($builder) use ($terms) {
            foreach ($terms as $index => $term) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}('route', 'like', '%'.$term.'%');
            }
        });
    }

    private function domainTerms(string $domain): array
    {
        return [
            'payments'=>['payment','pix','checkout','refund'], 'commerce'=>['order','cart','commerce','purchase'],
            'scheduling'=>['appointment','availability','booking','schedule'], 'establishments'=>['establishment'],
            'catalog'=>['item','catalog','product','service'], 'identity'=>['auth','login','logout','user','profile'],
            'notifications'=>['notification','mail','email'], 'events'=>['event','ticket','production'],
            'files'=>['file','storage','upload','media'], 'runtime'=>['queue','job','scheduler','backup'],
            'fulfillment'=>['fulfillment','pickup','delivery','redeem'],
        ][$domain] ?? [];
    }

    private function affectedApplicationSlugs(int $issueId): array
    {
        if ($issueId <= 0 || ! Schema::hasTable('operational_issue_occurrences') || ! Schema::hasTable('applications')) return [];
        return DB::table('operational_issue_occurrences as o')->join('applications as a', 'a.id', '=', 'o.application_id')
            ->where('o.operational_issue_id', $issueId)->whereNotNull('a.slug')->distinct()->pluck('a.slug')->all();
    }

    private function routeRoot(mixed $route): ?string
    {
        $route = trim((string) $route, '/ ');
        if ($route === '') return null;
        $parts = array_values(array_filter(explode('/', $route)));
        return implode('/', array_slice($parts, 0, min(3, count($parts))));
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) return $value;
        if (! is_string($value) || $value === '') return null;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function decodeJsonFields(array $row, array $fields): array
    {
        foreach ($fields as $field) $row[$field] = $this->decodeJson($row[$field] ?? null);
        return $row;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nested(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) return null;
            $cursor = $cursor[$key];
        }
        return $cursor;
    }

    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) if ($value !== null && $value !== '') return $value;
        return null;
    }
}
