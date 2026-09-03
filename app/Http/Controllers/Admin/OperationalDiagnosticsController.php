<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationalIssueClassifier;
use App\Services\Operations\OperationalIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalDiagnosticsController extends Controller
{
    public function __construct(
        private readonly OperationalIssueClassifier $classifier,
        private readonly OperationalIssueService $issues,
    ) {
    }

    public function security(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $hours = max(1, min((int) $request->integer('hours', 24), 168));
        $limit = max(50, min((int) $request->integer('limit', 250), 500));

        if (! Schema::hasTable('interactions')) {
            return response()->json($this->emptyPayload($hours));
        }

        $columns = Schema::getColumnListing('interactions');
        $since = now()->subHours($hours);
        $base = DB::table('interactions as i')->where('i.created_at', '>=', $since);

        $critical = in_array('severity', $columns, true)
            ? (clone $base)->where('i.severity', 'critical')->count()
            : 0;
        $suspicious = in_array('severity', $columns, true)
            ? (clone $base)->where('i.severity', 'suspicious')->count()
            : 0;
        $attention = in_array('severity', $columns, true)
            ? (clone $base)->where('i.severity', 'attention')->count()
            : 0;
        $errors = in_array('outcome', $columns, true)
            ? (clone $base)->where('i.outcome', 'error')->count()
            : 0;
        $denied = in_array('outcome', $columns, true)
            ? (clone $base)->whereIn('i.outcome', ['denied', 'refused'])->count()
            : 0;

        $relevant = $this->relevantQuery($since, $columns);
        $relevantCount = (clone $relevant)->count();

        $syncSince = now()->subHours(max(48, $hours * 2));
        $syncRows = $this->relevantQuery($syncSince, $columns)
            ->orderByDesc('i.id')
            ->limit(1500)
            ->get();

        $applicationMap = $this->applicationMap($syncRows);
        $userMap = $this->userMap($syncRows);
        $syncEvents = $syncRows
            ->map(fn ($row) => $this->normalizeEvent($row, $applicationMap, $userMap))
            ->values();

        $this->issues->sync($syncEvents);

        $sinceTimestamp = $since->timestamp;
        $events = $syncEvents
            ->filter(fn (array $event) => $event['occurred_at'] && strtotime((string) $event['occurred_at']) >= $sinceTimestamp)
            ->take($limit)
            ->values();

        $groupCounts = $events->countBy('fingerprint');
        $events = $events->map(function (array $event) use ($groupCounts) {
            $event['occurrence_count'] = (int) ($groupCounts[$event['fingerprint']] ?? 1);
            return $event;
        });

        $groups = $events
            ->groupBy('fingerprint')
            ->map(function ($rows, string $fingerprint) {
                $latest = $rows->first();
                $users = $rows->pluck('user.id')->filter()->unique()->count();
                $apps = $rows->pluck('application.id')->filter()->unique()->count();
                $severity = $rows->contains(fn ($event) => $event['severity'] === 'critical')
                    ? 'critical'
                    : ($rows->contains(fn ($event) => $event['severity'] === 'suspicious') ? 'suspicious' : ($latest['severity'] ?? 'attention'));
                $impactEvent = $latest;
                $impactEvent['severity'] = $severity;
                $impact = $this->classifier->impactScore($impactEvent, $rows->count(), $users, $apps);

                return [
                    'fingerprint' => $fingerprint,
                    'occurrences' => $rows->count(),
                    'first_seen_at' => $rows->last()['occurred_at'] ?? null,
                    'last_seen_at' => $latest['occurred_at'] ?? null,
                    'severity' => $severity,
                    'category' => $latest['category'] ?? 'operational',
                    'domain' => $latest['domain'] ?? 'platform',
                    'impact_score' => $impact,
                    'priority' => $this->classifier->priority($impact),
                    'http_status' => $latest['http_status'] ?? null,
                    'error_code' => $latest['error_code'] ?? null,
                    'message' => $latest['message'] ?? null,
                    'application' => $latest['application'] ?? null,
                    'method' => $latest['method'] ?? null,
                    'route' => $latest['route'] ?? null,
                    'sample_request_id' => $latest['request_id'] ?? null,
                ];
            })
            ->sortByDesc('impact_score')
            ->values();

        $impactedApps = $events
            ->map(fn ($event) => $event['application']['slug'] ?? $event['application']['name'] ?? null)
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'diagnostics_version' => 2,
            'window_hours' => $hours,
            'critical_events_24h' => $critical,
            'suspicious_24h' => $suspicious,
            'attention_24h' => $attention,
            'denied_24h' => $denied,
            'errors_24h' => $errors,
            'total_relevant_events' => $relevantCount,
            'unique_issues' => $groups->count(),
            'repeated_events' => max(0, $events->count() - $groups->count()),
            'impacted_applications' => $impactedApps,
            'operational_issues' => $this->issues->summary(),
            'truncated' => $relevantCount > $events->count(),
            'groups' => $groups,
            'events' => $events,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function relevantQuery($since, array $columns)
    {
        $query = DB::table('interactions as i')->where('i.created_at', '>=', $since);
        if (in_array('severity', $columns, true) || in_array('outcome', $columns, true)) {
            $query->where(function ($builder) use ($columns) {
                if (in_array('severity', $columns, true)) {
                    $builder->whereIn('i.severity', ['attention', 'suspicious', 'critical']);
                }
                if (in_array('outcome', $columns, true)) {
                    $method = in_array('severity', $columns, true) ? 'orWhereIn' : 'whereIn';
                    $builder->{$method}('i.outcome', ['denied', 'refused', 'error']);
                }
            });
        }
        return $query;
    }

    private function normalizeEvent(object $row, array $applicationMap, array $userMap): array
    {
        $content = $this->decodeContent($row->content ?? null);
        $userSnapshot = is_array($content['user_snapshot'] ?? null) ? $content['user_snapshot'] : [];
        $entitySnapshot = is_array($content['entity_snapshot'] ?? null) ? $content['entity_snapshot'] : [];
        $application = $applicationMap[(int) ($row->app_id ?? 0)] ?? null;
        $fallbackUser = $userMap[(int) ($row->user_id ?? 0)] ?? null;

        $app = array_filter([
            'id' => $row->app_id ?? ($content['app_id'] ?? null),
            'name' => $application['name'] ?? ($content['app_name'] ?? null),
            'slug' => $application['slug'] ?? ($content['app_slug'] ?? null),
            'version' => $application['version'] ?? ($content['app_version'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        $user = array_filter([
            'id' => $row->user_id ?? ($userSnapshot['id'] ?? null),
            'name' => $userSnapshot['name'] ?? ($fallbackUser['name'] ?? null),
            'email' => $userSnapshot['email'] ?? ($fallbackUser['email'] ?? null),
            'profile' => $userSnapshot['profile'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $statusValue = $row->http_status ?? ($content['status'] ?? null);
        $status = is_numeric($statusValue) ? (int) $statusValue : null;
        $message = $content['error'] ?? $content['response_message'] ?? $row->name ?? 'Evento operacional sem mensagem detalhada';
        $errorCode = $content['error_code'] ?? ($status ? 'HTTP_'.$status : null);
        $route = $row->route ?? $content['path'] ?? null;
        $method = $row->method ?? null;

        $event = [
            'id' => (int) $row->id,
            'occurred_at' => $row->created_at ?? null,
            'interaction_type' => $row->interaction_type ?? null,
            'outcome' => $row->outcome ?? null,
            'severity' => $row->severity ?? 'normal',
            'environment' => $row->environment ?? null,
            'application' => $app ?: null,
            'method' => $method,
            'route' => $route,
            'route_name' => $content['route_name'] ?? null,
            'path' => $content['path'] ?? null,
            'frontend_page' => $content['frontend_page'] ?? null,
            'http_status' => $status,
            'error_code' => $errorCode,
            'message' => is_scalar($message) ? mb_substr((string) $message, 0, 1000) : 'Erro sem mensagem textual',
            'request_id' => $row->request_id ?? null,
            'correlation_id' => $row->correlation_id ?? null,
            'parent_interaction_id' => $row->parent_interaction_id ?? null,
            'duration_ms' => is_numeric($row->duration_ms ?? null)
                ? (int) $row->duration_ms
                : (isset($content['duration_ms']) && is_numeric($content['duration_ms']) ? (int) $content['duration_ms'] : null),
            'user' => $user ?: null,
            'entity' => $entitySnapshot ?: array_filter([
                'type' => $row->entity_type ?? null,
                'id' => $row->entity_id ?? null,
                'name' => $row->name ?? null,
            ]),
            'client' => array_filter([
                'device' => $content['device'] ?? null,
                'browser' => $content['browser'] ?? null,
                'operating_system' => $content['operating_system'] ?? null,
            ]),
            'network' => array_filter([
                'ip' => $content['ip'] ?? ($row->ip ?? null),
                'origin' => $content['origin'] ?? null,
                'referer' => $content['referer'] ?? null,
            ]),
            'request_context' => array_filter([
                'parameters' => $content['parameters'] ?? null,
                'query' => $content['query'] ?? null,
                'location' => $content['location'] ?? null,
                'application_context' => $content['application_context'] ?? null,
            ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
        ];

        $event['fingerprint'] = $this->fingerprint($event);
        $event['category'] = $this->classifier->category($event);
        $event['domain'] = $this->classifier->domain($event);
        $event['impact_score'] = $this->classifier->impactScore($event);
        $event['priority'] = $this->classifier->priority($event['impact_score']);
        return $event;
    }

    private function fingerprint(array $event): string
    {
        $message = $this->normalizeVolatile((string) ($event['message'] ?? ''));
        $route = $this->normalizeVolatile((string) ($event['route_name'] ?? $event['route'] ?? 'unknown'));

        $source = implode('|', [
            $event['http_status'] ?? 'none',
            Str::upper((string) ($event['method'] ?? '')),
            Str::lower($route),
            Str::lower((string) ($event['error_code'] ?? 'unknown')),
            mb_substr(Str::lower($message), 0, 300),
        ]);

        return 'EVT-'.strtoupper(substr(hash('sha256', $source), 0, 12));
    }

    private function normalizeVolatile(string $value): string
    {
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{uuid}', $value) ?? $value;
        $value = preg_replace('/\b\d+\b/', '{n}', $value) ?? $value;
        return preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    }

    private function decodeContent(mixed $content): array
    {
        if (is_array($content)) return $content;
        if (! is_string($content) || $content === '') return [];
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function applicationMap($rows): array
    {
        if (! Schema::hasTable('applications')) return [];
        $ids = $rows->pluck('app_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return DB::table('applications')->whereIn('id', $ids)->get(['id', 'name', 'slug', 'version'])
            ->mapWithKeys(fn ($app) => [(int) $app->id => ['name' => $app->name, 'slug' => $app->slug, 'version' => $app->version]])->all();
    }

    private function userMap($rows): array
    {
        if (! Schema::hasTable('users')) return [];
        $ids = $rows->pluck('user_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'user_name', 'email'])
            ->mapWithKeys(function ($user) {
                $name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->user_name ?? null);
                return [(int) $user->id => ['name' => $name, 'email' => $user->email]];
            })->all();
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasProfile('Administrador') || $user->hasPermission('security_view') || $user->hasPermission('ecosystem_manage')),
            403,
            'Usuário sem permissão para acessar diagnósticos operacionais.'
        );
    }

    private function emptyPayload(int $hours): array
    {
        return [
            'diagnostics_version' => 2,
            'window_hours' => $hours,
            'critical_events_24h' => 0,
            'suspicious_24h' => 0,
            'attention_24h' => 0,
            'denied_24h' => 0,
            'errors_24h' => 0,
            'total_relevant_events' => 0,
            'unique_issues' => 0,
            'repeated_events' => 0,
            'impacted_applications' => [],
            'operational_issues' => $this->issues->summary(),
            'truncated' => false,
            'groups' => [],
            'events' => [],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
