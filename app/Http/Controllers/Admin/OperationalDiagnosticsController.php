<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalDiagnosticsController extends Controller
{
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
            ? (clone $base)->whereIn('i.severity', ['suspicious', 'critical'])->count()
            : 0;
        $errors = in_array('outcome', $columns, true)
            ? (clone $base)->where('i.outcome', 'error')->count()
            : 0;
        $denied = in_array('outcome', $columns, true)
            ? (clone $base)->whereIn('i.outcome', ['denied', 'refused'])->count()
            : 0;

        $relevant = (clone $base);
        if (in_array('severity', $columns, true) || in_array('outcome', $columns, true)) {
            $relevant->where(function ($query) use ($columns) {
                if (in_array('severity', $columns, true)) {
                    $query->whereIn('i.severity', ['attention', 'suspicious', 'critical']);
                }
                if (in_array('outcome', $columns, true)) {
                    $method = in_array('severity', $columns, true) ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('i.outcome', ['denied', 'refused', 'error']);
                }
            });
        }

        $relevantCount = (clone $relevant)->count();
        $rows = $relevant->orderByDesc('i.id')->limit($limit)->get();

        $applicationMap = $this->applicationMap($rows);
        $userMap = $this->userMap($rows);
        $events = $rows->map(fn ($row) => $this->normalizeEvent($row, $applicationMap, $userMap))->values();

        $groupCounts = $events->countBy('fingerprint');
        $events = $events->map(function (array $event) use ($groupCounts) {
            $event['occurrence_count'] = (int) ($groupCounts[$event['fingerprint']] ?? 1);
            return $event;
        });

        $groups = $events
            ->groupBy('fingerprint')
            ->map(function ($rows, string $fingerprint) {
                $latest = $rows->first();
                return [
                    'fingerprint' => $fingerprint,
                    'occurrences' => $rows->count(),
                    'first_seen_at' => $rows->last()['occurred_at'] ?? null,
                    'last_seen_at' => $latest['occurred_at'] ?? null,
                    'severity' => $rows->contains(fn ($event) => $event['severity'] === 'critical') ? 'critical' : ($latest['severity'] ?? 'attention'),
                    'http_status' => $latest['http_status'] ?? null,
                    'error_code' => $latest['error_code'] ?? null,
                    'message' => $latest['message'] ?? null,
                    'application' => $latest['application'] ?? null,
                    'method' => $latest['method'] ?? null,
                    'route' => $latest['route'] ?? null,
                    'sample_request_id' => $latest['request_id'] ?? null,
                ];
            })
            ->sortByDesc('occurrences')
            ->values();

        $impactedApps = $events
            ->map(fn ($event) => $event['application']['slug'] ?? $event['application']['name'] ?? null)
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'diagnostics_version' => 1,
            'window_hours' => $hours,
            'critical_events_24h' => $critical,
            'denied_24h' => $denied,
            'errors_24h' => $errors,
            'total_relevant_events' => $relevantCount,
            'unique_issues' => $groups->count(),
            'repeated_events' => max(0, $events->count() - $groups->count()),
            'impacted_applications' => $impactedApps,
            'truncated' => $relevantCount > $events->count(),
            'groups' => $groups,
            'events' => $events,
            'generated_at' => now()->toIso8601String(),
        ]);
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
        ], fn ($value) => $value !== null && $value !== '');

        $user = array_filter([
            'id' => $row->user_id ?? ($userSnapshot['id'] ?? null),
            'name' => $userSnapshot['name'] ?? ($fallbackUser['name'] ?? null),
            'email' => $userSnapshot['email'] ?? ($fallbackUser['email'] ?? null),
            'profile' => $userSnapshot['profile'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $status = isset($content['status']) && is_numeric($content['status']) ? (int) $content['status'] : null;
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
            'duration_ms' => isset($content['duration_ms']) ? (int) $content['duration_ms'] : null,
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
                'ip' => $content['ip'] ?? null,
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
        return $event;
    }

    private function fingerprint(array $event): string
    {
        $message = Str::lower((string) ($event['message'] ?? ''));
        $message = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{uuid}', $message) ?? $message;
        $message = preg_replace('/\b\d+\b/', '{n}', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        $source = implode('|', [
            $event['http_status'] ?? 'none',
            Str::upper((string) ($event['method'] ?? '')),
            Str::lower((string) ($event['route_name'] ?? $event['route'] ?? 'unknown')),
            Str::lower((string) ($event['error_code'] ?? 'unknown')),
            mb_substr($message, 0, 300),
        ]);

        return 'EVT-'.strtoupper(substr(hash('sha256', $source), 0, 12));
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

        return DB::table('applications')->whereIn('id', $ids)->get(['id', 'name', 'slug'])
            ->mapWithKeys(fn ($app) => [(int) $app->id => ['name' => $app->name, 'slug' => $app->slug]])->all();
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
            'diagnostics_version' => 1,
            'window_hours' => $hours,
            'critical_events_24h' => 0,
            'denied_24h' => 0,
            'errors_24h' => 0,
            'total_relevant_events' => 0,
            'unique_issues' => 0,
            'repeated_events' => 0,
            'impacted_applications' => [],
            'truncated' => false,
            'groups' => [],
            'events' => [],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
