<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalTelemetryService
{
    public function __construct(private readonly OperationalIssueClassifier $classifier)
    {
    }

    public function available(): bool
    {
        return Schema::hasTable('interactions');
    }

    public function counts(int $hours = 24): array
    {
        if (! $this->available()) return ['critical'=>0,'suspicious'=>0,'attention'=>0,'errors'=>0,'denied'=>0,'relevant'=>0];
        $hours = max(1, min($hours, 168));
        $columns = Schema::getColumnListing('interactions');
        $since = now()->subHours($hours);
        $base = DB::table('interactions as i')->where('i.created_at', '>=', $since);

        return [
            'critical' => in_array('severity', $columns, true) ? (clone $base)->where('i.severity', 'critical')->count() : 0,
            'suspicious' => in_array('severity', $columns, true) ? (clone $base)->where('i.severity', 'suspicious')->count() : 0,
            'attention' => in_array('severity', $columns, true) ? (clone $base)->where('i.severity', 'attention')->count() : 0,
            'errors' => in_array('outcome', $columns, true) ? (clone $base)->where('i.outcome', 'error')->count() : 0,
            'denied' => in_array('outcome', $columns, true) ? (clone $base)->whereIn('i.outcome', ['denied', 'refused'])->count() : 0,
            'relevant' => $this->relevantQuery($since, $columns)->count(),
        ];
    }

    public function events(int $hours = 48, int $limit = 1500): Collection
    {
        if (! $this->available()) return collect();
        $hours = max(1, min($hours, 336));
        $limit = max(1, min($limit, 5000));
        $columns = Schema::getColumnListing('interactions');
        $rows = $this->relevantQuery(now()->subHours($hours), $columns)->orderByDesc('i.id')->limit($limit)->get();
        $applicationMap = $this->applicationMap($rows);
        $userMap = $this->userMap($rows);

        return $rows->map(fn ($row) => $this->normalizeEvent($row, $applicationMap, $userMap))->values();
    }

    private function relevantQuery($since, array $columns)
    {
        $query = DB::table('interactions as i')->where('i.created_at', '>=', $since);
        if (in_array('severity', $columns, true) || in_array('outcome', $columns, true)) {
            $query->where(function ($builder) use ($columns) {
                if (in_array('severity', $columns, true)) $builder->whereIn('i.severity', ['attention', 'suspicious', 'critical']);
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
        $technical = $this->technicalContext($content);

        $event = [
            'id' => (int) $row->id,
            'occurred_at' => $row->created_at ?? null,
            'interaction_type' => $row->interaction_type ?? null,
            'outcome' => $row->outcome ?? null,
            'severity' => $row->severity ?? 'normal',
            'environment' => $row->environment ?? null,
            'application' => $app ?: null,
            'method' => $row->method ?? null,
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
            'duration_ms' => is_numeric($row->duration_ms ?? null) ? (int) $row->duration_ms : (isset($content['duration_ms']) && is_numeric($content['duration_ms']) ? (int) $content['duration_ms'] : null),
            'user' => $user ?: null,
            'entity' => $entitySnapshot ?: array_filter(['type'=>$row->entity_type ?? null,'id'=>$row->entity_id ?? null,'name'=>$row->name ?? null]),
            'client' => array_filter(['device'=>$content['device'] ?? null,'browser'=>$content['browser'] ?? null,'operating_system'=>$content['operating_system'] ?? null]),
            'network' => array_filter(['ip'=>$content['ip'] ?? ($row->ip ?? null),'origin'=>$content['origin'] ?? null,'referer'=>$content['referer'] ?? null]),
            'technical' => $technical,
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

    private function technicalContext(array $content): array
    {
        $exception = is_array($content['exception'] ?? null) ? $content['exception'] : [];
        $trace = $content['trace'] ?? $content['stack_trace'] ?? $exception['trace'] ?? null;
        if (is_array($trace)) $trace = array_slice($trace, 0, 12);
        elseif (is_string($trace)) $trace = mb_substr($trace, 0, 6000);

        return array_filter([
            'exception_class' => $content['exception_class'] ?? $exception['class'] ?? $exception['type'] ?? null,
            'file' => $content['exception_file'] ?? $content['file'] ?? $exception['file'] ?? null,
            'line' => $content['exception_line'] ?? $content['line'] ?? $exception['line'] ?? null,
            'trace' => $trace,
            'route_action' => $content['route_action'] ?? $content['controller_action'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function fingerprint(array $event): string
    {
        $message = $this->normalizeVolatile((string) ($event['message'] ?? ''));
        $route = $this->normalizeVolatile((string) ($event['route_name'] ?? $event['route'] ?? 'unknown'));
        $source = implode('|', [
            $event['http_status'] ?? 'none', Str::upper((string) ($event['method'] ?? '')),
            Str::lower($route), Str::lower((string) ($event['error_code'] ?? 'unknown')),
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

    private function applicationMap(Collection $rows): array
    {
        if (! Schema::hasTable('applications')) return [];
        $ids = $rows->pluck('app_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return [];
        return DB::table('applications')->whereIn('id', $ids)->get(['id','name','slug','version'])
            ->mapWithKeys(fn ($app) => [(int) $app->id => ['name'=>$app->name,'slug'=>$app->slug,'version'=>$app->version]])->all();
    }

    private function userMap(Collection $rows): array
    {
        if (! Schema::hasTable('users')) return [];
        $ids = $rows->pluck('user_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return [];
        $columns = Schema::getColumnListing('users');
        $select = array_values(array_intersect(['id','first_name','last_name','user_name','email'], $columns));
        return DB::table('users')->whereIn('id', $ids)->get($select)->mapWithKeys(function ($user) {
            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: ($user->user_name ?? null);
            return [(int) $user->id => ['name'=>$name,'email'=>$user->email ?? null]];
        })->all();
    }
}
