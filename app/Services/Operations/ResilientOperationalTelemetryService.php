<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production-safe facade for Mission Control telemetry.
 *
 * Operational observability must never become a new source of outage for the
 * Admin Center. If an optional observability table is partially migrated or a
 * legacy schema is still being reconciled, Mission Control returns an
 * indeterminate snapshot instead of propagating a 500 response.
 */
class ResilientOperationalTelemetryService extends OperationalTelemetryService
{
    public function overview(): array
    {
        try {
            return parent::overview();
        } catch (Throwable $exception) {
            $this->recordFailure('overview', $exception);

            return $this->fallbackOverview();
        }
    }

    public function securitySnapshot(bool $detail = false): array
    {
        try {
            return parent::securitySnapshot($detail);
        } catch (Throwable $exception) {
            $this->recordFailure('security', $exception);

            return [
                'critical_events_24h' => 0,
                'suspicious_24h' => 0,
                'denied_24h' => 0,
                'errors_24h' => 0,
                'events' => [],
                'status' => 'indeterminate',
            ];
        }
    }

    public function queueSnapshot(bool $detail = false): array
    {
        try {
            return parent::queueSnapshot($detail);
        } catch (Throwable $exception) {
            $this->recordFailure('queues', $exception);

            return [
                'queued' => 0,
                'failed' => 0,
                'oldest_available_at' => null,
                'status' => 'indeterminate',
                ...($detail ? ['failed_rows' => []] : []),
            ];
        }
    }

    public function runtimeSnapshot(): array
    {
        try {
            return parent::runtimeSnapshot();
        } catch (Throwable $exception) {
            $this->recordFailure('runtime', $exception);

            $databaseConnected = false;
            $driver = null;
            try {
                DB::connection()->getPdo();
                $databaseConnected = true;
                $driver = DB::connection()->getDriverName();
            } catch (Throwable) {
                // The fallback itself must remain side-effect free and safe.
            }

            return [
                'scheduler' => ['status' => 'unknown', 'last_seen_at' => null],
                'backup' => ['status' => 'unknown', 'last_success_at' => null, 'meta' => null],
                'probe' => ['status' => 'unknown', 'last_seen_at' => null, 'meta' => null],
                'database' => ['driver' => $driver, 'connected' => $databaseConnected],
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
            ];
        }
    }

    private function fallbackOverview(): array
    {
        $applications = $this->fallbackApplications();
        $queues = $this->queueSnapshot();
        $runtime = $this->runtimeSnapshot();
        $security = $this->securitySnapshot();
        $incidents = $this->fallbackIncidents();

        return [
            'score' => 0,
            'observability_score' => 0,
            'status' => 'indeterminate',
            'degraded' => true,
            'warnings' => [
                'Parte da telemetria operacional está indisponível. O Mission Control permaneceu online com dados seguros e parciais.',
            ],
            'applications' => $applications,
            'queues' => $queues,
            'runtime' => $runtime,
            'security' => $security,
            'incidents' => $incidents->values(),
            'summary' => [
                'applications' => $applications->count(),
                'operational' => 0,
                'degraded' => 0,
                'down' => 0,
                'unknown' => $applications->where('status', 'unknown')->count(),
                'open_incidents' => $incidents->count(),
                'critical_incidents' => $incidents->where('severity', 'critical')->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function fallbackApplications(): Collection
    {
        if (! Schema::hasTable('applications')) {
            return collect();
        }

        try {
            $available = Schema::getColumnListing('applications');
            $columns = array_values(array_intersect(
                ['id', 'name', 'slug', 'url', 'logo', 'version', 'is_active'],
                $available
            ));

            if (! in_array('id', $columns, true)) {
                return collect();
            }

            return DB::table('applications')
                ->select($columns)
                ->orderBy(in_array('name', $columns, true) ? 'name' : 'id')
                ->get()
                ->map(function ($app) {
                    return [
                        'id' => $app->id,
                        'name' => $app->name ?? ('Aplicação #' . $app->id),
                        'slug' => $app->slug ?? null,
                        'url' => $app->url ?? null,
                        'logo' => $app->logo ?? null,
                        'version' => $app->version ?? null,
                        'status' => isset($app->is_active) && ! $app->is_active ? 'disabled' : 'unknown',
                        'last_activity_at' => null,
                        'requests_24h' => 0,
                        'errors_24h' => 0,
                        'error_rate_24h' => 0,
                        'avg_latency_ms_1h' => null,
                        'probe_http_status' => null,
                        'probe_latency_ms' => null,
                        'last_probe_at' => null,
                        'probe_error' => null,
                        'telemetry_fresh' => false,
                    ];
                })
                ->values();
        } catch (Throwable $exception) {
            $this->recordFailure('applications_fallback', $exception);
            return collect();
        }
    }

    private function fallbackIncidents(): Collection
    {
        if (! Schema::hasTable('admin_incidents')) {
            return collect();
        }

        try {
            $columns = Schema::getColumnListing('admin_incidents');
            if (! in_array('status', $columns, true)) {
                return collect();
            }

            $query = DB::table('admin_incidents')
                ->whereIn('status', ['open', 'acknowledged', 'investigating']);

            if (in_array('created_at', $columns, true)) {
                $query->orderByDesc('created_at');
            } elseif (in_array('id', $columns, true)) {
                $query->orderByDesc('id');
            }

            return $query->limit(20)->get();
        } catch (Throwable $exception) {
            $this->recordFailure('incidents_fallback', $exception);
            return collect();
        }
    }

    private function recordFailure(string $component, Throwable $exception): void
    {
        Log::warning('mission_control.telemetry_component_failed', [
            'component' => $component,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
