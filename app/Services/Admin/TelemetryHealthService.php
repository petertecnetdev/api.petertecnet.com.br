<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\Interaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TelemetryHealthService
{
    public function health(): array
    {
        return Cache::remember('admin:telemetry:health:v2', now()->addSeconds(15), fn () => $this->buildHealth());
    }

    public function journeys(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 20), 50));
        $rowLimit = max(200, min($limit * 80, 2000));

        $query = $this->frontendQuery()
            ->with([
                'user:id,first_name,last_name,user_name,email,avatar',
                'application:id,name,slug,logo',
            ])
            ->whereNotNull('correlation_id');

        if (! empty($filters['app_id'])) {
            $query->where('app_id', (int) $filters['app_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        $journeys = $query->latest('id')
            ->limit($rowLimit)
            ->get()
            ->groupBy('correlation_id')
            ->map(fn (Collection $rows, string $session) => $this->journeySummary($session, $rows))
            ->sortByDesc('last_at')
            ->take($limit)
            ->values();

        return [
            'journeys' => $journeys,
            'count' => $journeys->count(),
            'filters' => [
                'app_id' => isset($filters['app_id']) ? (int) $filters['app_id'] : null,
                'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
                'from' => $filters['from'] ?? null,
            ],
        ];
    }

    public function journey(string $session): array
    {
        $rows = $this->frontendQuery()
            ->with([
                'user:id,first_name,last_name,user_name,email,avatar',
                'application:id,name,slug,logo',
            ])
            ->where('correlation_id', $session)
            ->oldest('id')
            ->limit(500)
            ->get();

        abort_if($rows->isEmpty(), 404, 'Jornada de telemetria não encontrada.');

        return [
            'journey' => $this->journeySummary($session, $rows),
            'events' => $rows->map(fn (Interaction $interaction) => $this->eventPayload($interaction))->values(),
        ];
    }

    private function buildHealth(): array
    {
        $now = now();
        $sinceDay = $now->copy()->subDay();
        $sinceHour = $now->copy()->subHour();
        $sinceQuarter = $now->copy()->subMinutes(15);
        $expectedSchema = (string) config('telemetry.frontend_schema', '3');
        $expectedVersion = (string) config('telemetry.frontend_version', '3.3.0');
        $staleMinutes = max((int) config('telemetry.stale_minutes', 60), 5);
        $downMinutes = max((int) config('telemetry.down_minutes', 1440), $staleMinutes + 1);

        $applications = Application::query()
            ->select(['id', 'name', 'slug', 'logo', 'url', 'is_active'])
            ->orderBy('name')
            ->get();

        $appIds = $applications->pluck('id');

        $stats = $this->frontendQuery()
            ->whereIn('app_id', $appIds)
            ->where('created_at', '>=', $sinceDay)
            ->selectRaw(
                'app_id,
                 COUNT(*) AS events_24h,
                 SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS events_1h,
                 SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS events_15m,
                 SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) AS errors_24h,
                 COUNT(DISTINCT CASE WHEN created_at >= ? THEN correlation_id END) AS active_sessions_15m',
                [$sinceHour, $sinceQuarter, 'error', $sinceQuarter]
            )
            ->groupBy('app_id')
            ->get()
            ->keyBy('app_id');

        $latestIds = $this->frontendQuery()
            ->whereIn('app_id', $appIds)
            ->selectRaw('MAX(id)')
            ->groupBy('app_id');

        $latest = Interaction::query()
            ->whereIn('id', $latestIds)
            ->get(['id', 'app_id', 'interaction_type', 'outcome', 'correlation_id', 'content', 'created_at'])
            ->keyBy('app_id');

        $versionRows = $this->frontendQuery()
            ->whereIn('app_id', $appIds)
            ->where('created_at', '>=', $sinceDay)
            ->get(['app_id', 'content'])
            ->groupBy('app_id');

        $apps = $applications->map(function (Application $application) use (
            $stats,
            $latest,
            $versionRows,
            $now,
            $expectedSchema,
            $expectedVersion,
            $staleMinutes,
            $downMinutes
        ) {
            $stat = $stats->get($application->id);
            $last = $latest->get($application->id);
            $recent = $versionRows->get($application->id, collect());
            $events24h = (int) ($stat?->events_24h ?? 0);
            $errors24h = (int) ($stat?->errors_24h ?? 0);
            $errorRate = $events24h > 0 ? round($errors24h / $events24h, 4) : 0.0;

            $schemaCounts = $recent
                ->map(fn ($row) => (string) ($row->content['telemetry_schema'] ?? '1'))
                ->countBy()
                ->sortDesc();
            $versionCounts = $recent
                ->map(fn ($row) => (string) ($row->content['telemetry_version'] ?? 'unknown'))
                ->countBy()
                ->sortDesc();

            $latestSchema = (string) ($last?->content['telemetry_schema'] ?? '');
            $latestVersion = (string) ($last?->content['telemetry_version'] ?? '');
            $lastAt = $last?->created_at;
            $ageMinutes = $lastAt ? max(0, $lastAt->diffInMinutes($now)) : null;
            $alerts = [];
            $status = $application->is_active ? 'healthy' : 'inactive';

            if ($application->is_active) {
                if (! $lastAt) {
                    $status = 'warning';
                    $alerts[] = ['code' => 'no_telemetry', 'level' => 'warning', 'message' => 'Nenhuma telemetria frontend recebida.'];
                } elseif ($ageMinutes >= $downMinutes) {
                    $status = 'down';
                    $alerts[] = ['code' => 'telemetry_down', 'level' => 'critical', 'message' => "Sem eventos há {$ageMinutes} minutos."];
                } elseif ($ageMinutes >= $staleMinutes) {
                    $status = 'warning';
                    $alerts[] = ['code' => 'telemetry_stale', 'level' => 'warning', 'message' => "Último evento há {$ageMinutes} minutos."];
                }

                if ($latestSchema !== '' && $latestSchema !== $expectedSchema) {
                    $status = $status === 'down' ? 'down' : 'warning';
                    $alerts[] = [
                        'code' => 'schema_outdated',
                        'level' => 'warning',
                        'message' => "Schema {$latestSchema}; esperado {$expectedSchema}.",
                    ];
                }

                if (
                    $latestVersion !== ''
                    && $latestVersion !== 'unknown'
                    && version_compare($latestVersion, $expectedVersion, '<')
                ) {
                    $status = $status === 'down' ? 'down' : 'warning';
                    $alerts[] = [
                        'code' => 'sdk_outdated',
                        'level' => 'warning',
                        'message' => "SDK {$latestVersion}; esperado {$expectedVersion}.",
                    ];
                }

                if ($events24h >= 20 && $errorRate >= 0.10) {
                    $status = $status === 'down' ? 'down' : 'warning';
                    $alerts[] = [
                        'code' => 'elevated_error_rate',
                        'level' => $errorRate >= 0.25 ? 'critical' : 'warning',
                        'message' => 'Taxa de erro frontend de '.number_format($errorRate * 100, 1, ',', '.').'%.',
                    ];
                }
            }

            return [
                'id' => $application->id,
                'name' => $application->name,
                'slug' => $application->slug,
                'logo' => $application->logo,
                'url' => $application->url,
                'is_active' => (bool) $application->is_active,
                'status' => $status,
                'last_event_at' => $lastAt?->toIso8601String(),
                'last_event_type' => $last?->interaction_type,
                'events_15m' => (int) ($stat?->events_15m ?? 0),
                'events_1h' => (int) ($stat?->events_1h ?? 0),
                'events_24h' => $events24h,
                'errors_24h' => $errors24h,
                'error_rate_24h' => $errorRate,
                'active_sessions_15m' => (int) ($stat?->active_sessions_15m ?? 0),
                'latest_schema' => $latestSchema ?: null,
                'latest_version' => $latestVersion ?: null,
                'schemas_24h' => $schemaCounts->all(),
                'versions_24h' => $versionCounts->all(),
                'alerts' => $alerts,
            ];
        })->values();

        $active = $apps->where('is_active', true);

        return [
            'generated_at' => $now->toIso8601String(),
            'expected' => [
                'schema' => $expectedSchema,
                'version' => $expectedVersion,
                'stale_minutes' => $staleMinutes,
                'down_minutes' => $downMinutes,
            ],
            'summary' => [
                'applications' => $apps->count(),
                'active_applications' => $active->count(),
                'healthy' => $active->where('status', 'healthy')->count(),
                'warning' => $active->where('status', 'warning')->count(),
                'down' => $active->where('status', 'down')->count(),
                'events_15m' => $apps->sum('events_15m'),
                'events_1h' => $apps->sum('events_1h'),
                'events_24h' => $apps->sum('events_24h'),
                'active_sessions_15m' => $apps->sum('active_sessions_15m'),
                'errors_24h' => $apps->sum('errors_24h'),
                'alerts' => $apps->sum(fn ($app) => count($app['alerts'])),
            ],
            'realtime' => [
                'broadcast_driver' => (string) config('broadcasting.default'),
                'reverb_configured' => filled(config('broadcasting.connections.reverb.key'))
                    && filled(config('broadcasting.connections.reverb.options.host')),
            ],
            'applications' => $apps,
        ];
    }

    private function journeySummary(string $session, Collection $rows): array
    {
        $ordered = $rows->sortBy('id')->values();
        $first = $ordered->first();
        $last = $ordered->last();
        $applications = $ordered->pluck('application')->filter()->unique('id')->values();
        $users = $ordered->pluck('user')->filter()->unique('id')->values();

        return [
            'session_id' => $session,
            'started_at' => $first?->created_at?->toIso8601String(),
            'last_at' => $last?->created_at?->toIso8601String(),
            'duration_ms' => $first?->created_at && $last?->created_at
                ? max(0, $first->created_at->diffInMilliseconds($last->created_at))
                : 0,
            'events' => $ordered->count(),
            'errors' => $ordered->where('outcome', 'error')->count(),
            'screens' => $ordered->where('interaction_type', 'frontend_screen_view')->count(),
            'applications' => $applications->map(fn ($app) => ['id' => $app->id, 'name' => $app->name, 'slug' => $app->slug])->values(),
            'users' => $users->map(fn ($user) => [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->user_name,
                'email' => $user->email,
            ])->values(),
            'entry_page' => $first?->content['frontend_page'] ?? null,
            'last_page' => $last?->content['frontend_page'] ?? null,
            'last_event_type' => $last?->interaction_type,
            'outcome' => $ordered->contains(fn ($row) => $row->outcome === 'error') ? 'error' : 'success',
        ];
    }

    private function eventPayload(Interaction $interaction): array
    {
        $content = is_array($interaction->content) ? $interaction->content : [];
        $metadata = is_array($content['metadata'] ?? null) ? $content['metadata'] : [];

        return [
            'id' => $interaction->id,
            'type' => $interaction->interaction_type,
            'name' => $interaction->name,
            'outcome' => $interaction->outcome,
            'severity' => $interaction->severity,
            'created_at' => $interaction->created_at?->toIso8601String(),
            'client_timestamp' => $content['client_timestamp'] ?? null,
            'page' => $content['frontend_page'] ?? null,
            'label' => $content['label'] ?? null,
            'target' => $content['target'] ?? null,
            'status' => $content['status'] ?? ($metadata['status'] ?? null),
            'duration_ms' => $metadata['duration_ms'] ?? null,
            'entity_type' => $interaction->entity_type,
            'entity_id' => $interaction->entity_id,
            'application' => $interaction->application ? [
                'id' => $interaction->application->id,
                'name' => $interaction->application->name,
                'slug' => $interaction->application->slug,
            ] : null,
            'user' => $interaction->user ? [
                'id' => $interaction->user->id,
                'name' => trim(($interaction->user->first_name ?? '').' '.($interaction->user->last_name ?? '')) ?: $interaction->user->user_name,
                'email' => $interaction->user->email,
            ] : null,
            'metadata' => collect($metadata)->only([
                'screen', 'previous_screen', 'previous_duration_ms', 'resource', 'action',
                'operation', 'entity_type', 'entity_id', 'entity_key', 'source', 'context',
            ])->all(),
        ];
    }

    private function frontendQuery()
    {
        return Interaction::query()->where('interaction_type', 'like', 'frontend_%');
    }
}
