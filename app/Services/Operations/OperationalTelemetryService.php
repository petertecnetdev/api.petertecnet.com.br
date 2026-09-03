<?php

namespace App\Services\Operations;

use App\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OperationalTelemetryService
{
    public function overview(): array
    {
        $now = now();
        $apps = $this->applications();
        $interactionMetrics = $this->interactionMetrics($now);
        $latestProbes = $this->latestProbes();

        $applications = $apps->map(function ($app) use ($interactionMetrics, $latestProbes, $now) {
            $metrics = $interactionMetrics->get($app->id);
            $probe = $latestProbes->get($app->id);
            $lastActivity = $metrics?->last_activity_at;
            $requests24h = (int) ($metrics?->requests_24h ?? 0);
            $errors24h = (int) ($metrics?->errors_24h ?? 0);
            $errorRate = $requests24h > 0 ? round(($errors24h / $requests24h) * 100, 2) : 0.0;
            $active = isset($app->is_active) ? (bool) $app->is_active : true;
            $probeFresh = $probe?->checked_at && strtotime((string) $probe->checked_at) >= $now->copy()->subMinutes(3)->timestamp;

            if (! $active) {
                $status = 'disabled';
            } elseif ($probe && $probeFresh) {
                $status = $probe->status;
            } elseif ($lastActivity && strtotime((string) $lastActivity) >= $now->copy()->subDay()->timestamp) {
                $status = 'unknown';
            } else {
                $status = 'unknown';
            }

            if ($status === 'operational' && $errorRate >= 10) {
                $status = 'degraded';
            }

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
                'avg_latency_ms_1h' => isset($metrics?->avg_latency_ms_1h) ? (int) round((float) $metrics->avg_latency_ms_1h) : null,
                'probe_http_status' => $probe?->http_status,
                'probe_latency_ms' => $probe?->latency_ms,
                'last_probe_at' => $probe?->checked_at,
                'probe_error' => $probe?->error,
                'telemetry_fresh' => (bool) $probeFresh,
            ];
        })->values();

        $queues = $this->queueSnapshot();
        $runtime = $this->runtimeSnapshot();
        $security = $this->securitySnapshot();
        $incidents = $this->openIncidents();
        $observabilityScore = $this->observabilityScore($applications, $runtime);

        $score = 100;
        $score -= min($applications->where('status', 'down')->count() * 18, 54);
        $score -= min($applications->where('status', 'degraded')->count() * 10, 30);
        $score -= min($applications->where('status', 'unknown')->count() * 4, 24);
        $score -= min(((int) ($queues['failed'] ?? 0)) * 4, 20);
        $score -= min(((int) ($security['critical_events_24h'] ?? 0)) * 3, 18);
        $score -= min($incidents->where('severity', 'critical')->count() * 8, 24);
        if (($runtime['scheduler']['status'] ?? 'unknown') !== 'healthy') $score -= 12;
        if (($runtime['backup']['status'] ?? 'unknown') !== 'healthy') $score -= 8;
        if (! ($runtime['database']['connected'] ?? false)) $score -= 30;
        $score = max(0, min(100, $score));

        $status = $observabilityScore < 50
            ? 'indeterminate'
            : ($score >= 90 ? 'healthy' : ($score >= 70 ? 'attention' : 'critical'));

        return [
            'score' => $score,
            'observability_score' => $observabilityScore,
            'status' => $status,
            'applications' => $applications,
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
        ];
    }

    public function securitySnapshot(bool $detail = false): array
    {
        $empty = ['critical_events_24h' => 0, 'suspicious_24h' => 0, 'denied_24h' => 0, 'errors_24h' => 0, 'events' => []];
        if (! Schema::hasTable('interactions') || ! Schema::hasColumn('interactions', 'created_at')) return $empty;

        $columns = Schema::getColumnListing('interactions');
        $base = DB::table('interactions')->where('created_at', '>=', now()->subDay());
        $denied = in_array('outcome', $columns, true) ? (clone $base)->where('outcome', 'denied')->count() : 0;
        $errors = in_array('outcome', $columns, true) ? (clone $base)->where('outcome', 'error')->count() : 0;
        $suspicious = in_array('severity', $columns, true) ? (clone $base)->where('severity', 'suspicious')->count() : 0;
        $critical = in_array('severity', $columns, true) ? (clone $base)->where('severity', 'critical')->count() : 0;
        $payload = ['critical_events_24h' => $critical + $suspicious, 'suspicious_24h' => $suspicious, 'denied_24h' => $denied, 'errors_24h' => $errors, 'events' => []];

        if ($detail) {
            $query = (clone $base)->orderByDesc(in_array('id', $columns, true) ? 'id' : 'created_at')->limit(100);
            if (in_array('severity', $columns, true)) $query->whereIn('severity', ['attention', 'suspicious', 'critical']);
            elseif (in_array('outcome', $columns, true)) $query->whereIn('outcome', ['denied', 'error']);
            $payload['events'] = $query->get();
        }

        return $payload;
    }

    public function queueSnapshot(bool $detail = false): array
    {
        $queued = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
        $payload = [
            'queued' => $queued,
            'failed' => $failed,
            'oldest_available_at' => $oldest ? date(DATE_ATOM, (int) $oldest) : null,
            'status' => $failed > 0 ? 'attention' : 'healthy',
        ];
        if ($detail) {
            $payload['failed_rows'] = Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get(['uuid', 'connection', 'queue', 'exception', 'failed_at'])
                : [];
        }
        return $payload;
    }

    public function runtimeSnapshot(): array
    {
        $heartbeats = Schema::hasTable('admin_runtime_heartbeats')
            ? DB::table('admin_runtime_heartbeats')->whereIn('service', ['scheduler', 'backup', 'probe'])->get()->keyBy('service')
            : collect();
        $scheduler = $heartbeats->get('scheduler');
        $backup = $heartbeats->get('backup');
        $probe = $heartbeats->get('probe');

        $schedulerFresh = $scheduler?->last_seen_at && strtotime((string) $scheduler->last_seen_at) >= now()->subMinutes(3)->timestamp;
        $backupFresh = $backup?->last_seen_at && strtotime((string) $backup->last_seen_at) >= now()->subHours(26)->timestamp;
        $probeFresh = $probe?->last_seen_at && strtotime((string) $probe->last_seen_at) >= now()->subMinutes(3)->timestamp;

        $databaseConnected = true;
        try { DB::connection()->getPdo(); } catch (\Throwable) { $databaseConnected = false; }

        return [
            'scheduler' => ['status' => $schedulerFresh ? ($scheduler->status ?: 'healthy') : 'unknown', 'last_seen_at' => $scheduler?->last_seen_at],
            'backup' => ['status' => $backupFresh ? ($backup->status ?: 'healthy') : 'unknown', 'last_success_at' => $backup?->last_seen_at, 'meta' => $this->decodeMeta($backup?->meta)],
            'probe' => ['status' => $probeFresh ? ($probe->status ?: 'healthy') : 'unknown', 'last_seen_at' => $probe?->last_seen_at, 'meta' => $this->decodeMeta($probe?->meta)],
            'database' => ['driver' => DB::connection()->getDriverName(), 'connected' => $databaseConnected],
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
        ];
    }

    public function probeApplications(): array
    {
        if (! Schema::hasTable('applications') || ! Schema::hasTable('admin_service_probes')) {
            return ['checked' => 0, 'operational' => 0, 'degraded' => 0, 'down' => 0];
        }

        $columns = array_values(array_intersect(['id', 'name', 'slug', 'url', 'is_active'], Schema::getColumnListing('applications')));
        $apps = Application::query()->get($columns ?: ['id']);
        $summary = ['checked' => 0, 'operational' => 0, 'degraded' => 0, 'down' => 0];

        foreach ($apps as $app) {
            if (isset($app->is_active) && ! $app->is_active) continue;
            $url = trim((string) ($app->url ?? ''));
            if ($url === '' || ! preg_match('#^https?://#i', $url)) continue;

            $started = microtime(true);
            $status = 'down';
            $httpStatus = null;
            $error = null;
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['User-Agent' => 'PeterTecnet-MissionControl/2.0'])
                    ->connectTimeout(4)
                    ->timeout(8)
                    ->get($url);
                $httpStatus = $response->status();
                $latency = (int) round((microtime(true) - $started) * 1000);
                if ($httpStatus >= 200 && $httpStatus < 500) {
                    $status = $latency > 2500 || $httpStatus >= 400 ? 'degraded' : 'operational';
                } else {
                    $status = 'down';
                    $error = 'HTTP ' . $httpStatus;
                }
            } catch (\Throwable $exception) {
                $latency = (int) round((microtime(true) - $started) * 1000);
                $error = substr($exception->getMessage(), 0, 500);
            }

            DB::table('admin_service_probes')->insert([
                'application_id' => $app->id,
                'status' => $status,
                'http_status' => $httpStatus,
                'latency_ms' => $latency,
                'error' => $error,
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $summary['checked']++;
            $summary[$status]++;
        }

        $this->heartbeat('probe', $summary['down'] > 0 ? 'attention' : 'healthy', $summary);
        return $summary;
    }

    public function heartbeatScheduler(): void
    {
        $this->heartbeat('scheduler', 'healthy', ['source' => 'operations:monitor']);
    }

    public function inspectBackup(): array
    {
        $paths = array_merge(
            glob(storage_path('app/backups/*')) ?: [],
            glob(storage_path('app/private/backups/*')) ?: []
        );
        $files = array_values(array_filter($paths, 'is_file'));
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $files[0] ?? null;
        $mtime = $latest ? filemtime($latest) : null;
        $healthy = $mtime && $mtime >= now()->subHours(26)->timestamp;
        $meta = [
            'file' => $latest ? basename($latest) : null,
            'size_bytes' => $latest ? filesize($latest) : null,
            'modified_at' => $mtime ? date(DATE_ATOM, $mtime) : null,
        ];
        if ($mtime) {
            $this->heartbeat('backup', $healthy ? 'healthy' : 'attention', $meta, date('Y-m-d H:i:s', $mtime));
        }
        return ['status' => $healthy ? 'healthy' : 'unknown', 'meta' => $meta];
    }

    public function rollupAndPrune(): void
    {
        if (! Schema::hasTable('admin_service_probes')) return;

        if (Schema::hasTable('admin_metric_rollups')) {
            $bucketStart = now()->startOfHour();
            $from = $bucketStart->copy()->subHour();
            $rows = DB::table('admin_service_probes')
                ->whereBetween('checked_at', [$from, $bucketStart])
                ->orderBy('latency_ms')
                ->get()
                ->groupBy('application_id');

            foreach ($rows as $applicationId => $probes) {
                $count = $probes->count();
                if ($count === 0) continue;
                $success = $probes->whereIn('status', ['operational', 'healthy'])->count();
                $errors = $count - $success;
                $latencies = $probes->pluck('latency_ms')->filter(fn ($value) => $value !== null)->map(fn ($value) => (int) $value)->sort()->values();
                $p95 = $this->percentile($latencies, 0.95);
                DB::table('admin_metric_rollups')->updateOrInsert(
                    ['application_id' => $applicationId, 'bucket_started_at' => $from, 'period' => 'hour'],
                    [
                        'probe_count' => $count,
                        'success_count' => $success,
                        'error_count' => $errors,
                        'avg_latency_ms' => $latencies->isNotEmpty() ? (int) round($latencies->avg()) : null,
                        'p95_latency_ms' => $p95,
                        'availability_percent' => round(($success / $count) * 100, 3),
                        'error_rate_percent' => round(($errors / $count) * 100, 3),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
            DB::table('admin_metric_rollups')->where('bucket_started_at', '<', now()->subDays(180))->delete();
        }

        DB::table('admin_service_probes')->where('checked_at', '<', now()->subDays(7))->delete();
    }

    public function applicationDetails(Application $application): array
    {
        $hasInteractions = Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'app_id');
        $activity = $hasInteractions ? DB::table('interactions')->where('app_id', $application->id) : null;
        return [
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
            'rollups' => Schema::hasTable('admin_metric_rollups') ? DB::table('admin_metric_rollups')->where('application_id', $application->id)->orderByDesc('bucket_started_at')->limit(168)->get() : [],
            'recent_activity' => $activity ? (clone $activity)->orderByDesc('id')->limit(30)->get() : [],
            'incidents' => Schema::hasTable('admin_incidents') ? DB::table('admin_incidents')->where('application_id', $application->id)->orderByDesc('id')->limit(30)->get() : [],
        ];
    }

    private function applications(): Collection
    {
        if (! Schema::hasTable('applications')) return collect();
        $columns = array_values(array_intersect(['id', 'name', 'slug', 'url', 'logo', 'version', 'is_active'], Schema::getColumnListing('applications')));
        return Application::query()->orderBy('name')->get($columns ?: ['id']);
    }

    private function interactionMetrics($now): Collection
    {
        if (! Schema::hasTable('interactions') || ! Schema::hasColumn('interactions', 'app_id') || ! Schema::hasColumn('interactions', 'created_at')) {
            return collect();
        }
        $columns = Schema::getColumnListing('interactions');
        $hasOutcome = in_array('outcome', $columns, true);
        $hasDuration = in_array('duration_ms', $columns, true);
        $day = $now->copy()->subDay()->toDateTimeString();
        $hour = $now->copy()->subHour()->toDateTimeString();

        $query = DB::table('interactions')
            ->select('app_id')
            ->selectRaw('MAX(created_at) as last_activity_at')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as requests_24h', [$day]);
        if ($hasOutcome) {
            $query->selectRaw("SUM(CASE WHEN created_at >= ? AND outcome = 'error' THEN 1 ELSE 0 END) as errors_24h", [$day]);
        } else {
            $query->selectRaw('0 as errors_24h');
        }
        if ($hasDuration) {
            $query->selectRaw('AVG(CASE WHEN created_at >= ? THEN duration_ms ELSE NULL END) as avg_latency_ms_1h', [$hour]);
        } else {
            $query->selectRaw('NULL as avg_latency_ms_1h');
        }
        return $query->groupBy('app_id')->get()->keyBy('app_id');
    }

    private function latestProbes(): Collection
    {
        if (! Schema::hasTable('admin_service_probes')) return collect();
        $latestTimes = DB::table('admin_service_probes')
            ->select('application_id', DB::raw('MAX(checked_at) as checked_at'))
            ->groupBy('application_id');
        return DB::table('admin_service_probes as p')
            ->joinSub($latestTimes, 'latest', function ($join) {
                $join->on('latest.application_id', '=', 'p.application_id')->on('latest.checked_at', '=', 'p.checked_at');
            })
            ->get(['p.application_id', 'p.status', 'p.http_status', 'p.latency_ms', 'p.error', 'p.checked_at'])
            ->keyBy('application_id');
    }

    private function openIncidents(): Collection
    {
        if (! Schema::hasTable('admin_incidents')) return collect();
        return DB::table('admin_incidents')
            ->whereIn('status', ['open', 'acknowledged', 'investigating'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->limit(20)->get();
    }

    private function observabilityScore(Collection $applications, array $runtime): int
    {
        $eligible = $applications->where('status', '!=', 'disabled');
        $appCoverage = $eligible->count() > 0 ? ($eligible->where('telemetry_fresh', true)->count() / $eligible->count()) * 70 : 70;
        $runtimeCoverage = 0;
        if (($runtime['scheduler']['status'] ?? 'unknown') !== 'unknown') $runtimeCoverage += 10;
        if (($runtime['backup']['status'] ?? 'unknown') !== 'unknown') $runtimeCoverage += 10;
        if (($runtime['probe']['status'] ?? 'unknown') !== 'unknown') $runtimeCoverage += 5;
        if (($runtime['database']['connected'] ?? false)) $runtimeCoverage += 5;
        return (int) round(min(100, $appCoverage + $runtimeCoverage));
    }

    private function heartbeat(string $service, string $status, array $meta = [], ?string $lastSeenAt = null): void
    {
        if (! Schema::hasTable('admin_runtime_heartbeats')) return;
        DB::table('admin_runtime_heartbeats')->updateOrInsert(
            ['service' => $service],
            [
                'status' => $status,
                'last_seen_at' => $lastSeenAt ?: now(),
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function percentile(Collection $values, float $percentile): ?int
    {
        if ($values->isEmpty()) return null;
        $index = (int) ceil($percentile * $values->count()) - 1;
        return (int) $values->get(max(0, min($index, $values->count() - 1)));
    }

    private function decodeMeta(?string $meta): ?array
    {
        if (! $meta) return null;
        $decoded = json_decode($meta, true);
        return is_array($decoded) ? $decoded : null;
    }
}
