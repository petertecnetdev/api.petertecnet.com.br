<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ActivityCenterService
{
    private const DEFAULT_RANGE = '24h';
    private const MAX_SAMPLE = 5000;

    public function index(array $filters): array
    {
        [$from, $to] = $this->window($filters);
        $query = $this->query($filters, $from, $to);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 20), 100);
        $total = (clone $query)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->with([
                'user:id,first_name,last_name,user_name,email,avatar,profile_id',
                'user.profile:id,name',
                'application:id,name,slug,logo,url',
            ])
            ->latest('id')
            ->forPage($page, $perPage)
            ->get();

        $context = $this->resourceContext($rows);

        return [
            'window' => $this->windowPayload($from, $to),
            'summary' => $this->listSummary($query),
            'activity' => $rows->map(fn (Interaction $item) => $this->payload($item, $context))->values(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
                'next_page' => $page < $lastPage ? $page + 1 : null,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function overview(array $filters): array
    {
        [$from, $to] = $this->window($filters);
        $base = $this->query($filters, $from, $to);
        $total = (clone $base)->count();
        $errors = (clone $base)->where(function ($query) {
            $query->where('outcome', 'error')
                ->orWhere('interaction_type', 'like', '%error%')
                ->orWhere('interaction_type', 'like', '%failed%');
        })->count();
        $critical = (clone $base)->where('severity', 'critical')->count();
        $attention = (clone $base)->whereIn('severity', ['attention', 'suspicious', 'critical'])->count();
        $errorRate = $total > 0 ? round(($errors / $total) * 100, 2) : 0.0;
        $sample = (clone $base)->latest('id')->limit(self::MAX_SAMPLE)->get([
            'id', 'user_id', 'app_id', 'entity_type', 'entity_id', 'session_key', 'outcome', 'severity', 'content', 'created_at',
        ]);
        $durations = $sample->map(fn (Interaction $row) => $this->durationMs($row->content ?? []))->filter(fn ($value) => $value !== null)->sort()->values();
        $p95 = $this->percentile($durations, 95);
        $avg = $durations->isNotEmpty() ? round((float) $durations->avg(), 1) : null;
        $establishmentIds = $this->establishmentIds($sample);
        $health = $this->health($errorRate, $critical, $p95);

        return [
            'window' => $this->windowPayload($from, $to),
            'health' => $health,
            'summary' => [
                'total' => $total,
                'users' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
                'anonymous' => (clone $base)->whereNull('user_id')->count(),
                'applications' => (clone $base)->whereNotNull('app_id')->distinct()->count('app_id'),
                'establishments' => $establishmentIds->count(),
                'sessions' => (clone $base)->whereNotNull('session_key')->distinct()->count('session_key'),
                'errors' => $errors,
                'error_rate' => $errorRate,
                'attention' => $attention,
                'critical' => $critical,
                'avg_duration_ms' => $avg,
                'p95_duration_ms' => $p95,
                'duration_sample_size' => $durations->count(),
                'establishment_sample_size' => $sample->count(),
            ],
            'timeline' => $this->timeline($base, $from, $to),
            'top_applications' => $this->topApplications($base),
            'top_types' => $this->topTypes($base),
            'top_users' => $this->topUsers($base),
            'top_establishments' => $this->topEstablishments($base),
            'sources' => $this->sourceBreakdown($sample),
            'devices' => $this->deviceBreakdown($sample),
            'latest_errors' => $this->latestErrors($base),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function facets(): array
    {
        $activeUserIds = Interaction::query()
            ->whereNotNull('user_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('user_id, COUNT(*) total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(100)
            ->pluck('user_id');

        $users = User::query()
            ->whereIn('id', $activeUserIds)
            ->get(['id', 'first_name', 'last_name', 'user_name', 'email', 'avatar'])
            ->keyBy('id');

        return [
            'applications' => Application::query()->orderBy('name')->get(['id', 'name', 'slug', 'logo', 'is_active']),
            'establishments' => Establishment::query()->latest('id')->limit(300)->get(['id', 'name', 'fantasy', 'app_id', 'city', 'uf']),
            'users' => $activeUserIds->map(fn ($id) => $users->get($id))->filter()->values(),
            'types' => Interaction::query()->select('interaction_type')->whereNotNull('interaction_type')->distinct()->orderBy('interaction_type')->pluck('interaction_type')->values(),
            'entity_types' => Interaction::query()->select('entity_type')->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type')->values(),
            'environments' => Interaction::query()->select('environment')->whereNotNull('environment')->distinct()->orderBy('environment')->pluck('environment')->values(),
            'outcomes' => ['success', 'pending', 'cancelled', 'denied', 'error'],
            'severities' => ['normal', 'attention', 'suspicious', 'critical'],
            'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            'sources' => ['frontend', 'domain', 'backend', 'system'],
            'ranges' => [
                ['value' => '1h', 'label' => 'Última hora'],
                ['value' => '24h', 'label' => 'Últimas 24 horas'],
                ['value' => '7d', 'label' => 'Últimos 7 dias'],
                ['value' => '30d', 'label' => 'Últimos 30 dias'],
                ['value' => '90d', 'label' => 'Últimos 90 dias'],
            ],
        ];
    }

    public function show(Interaction $interaction): array
    {
        $interaction->load([
            'user:id,first_name,last_name,user_name,email,avatar,profile_id',
            'user.profile:id,name',
            'application:id,name,slug,logo,url',
            'parentInteraction:id,interaction_type,name,created_at',
            'relatedInteractions:id,parent_interaction_id,interaction_type,name,created_at',
        ]);

        $related = Interaction::query()
            ->with(['user:id,first_name,last_name,user_name,email,avatar', 'application:id,name,slug,logo'])
            ->whereKeyNot($interaction->id)
            ->where(function ($query) use ($interaction) {
                if ($interaction->session_key) $query->orWhere('session_key', $interaction->session_key);
                if ($interaction->correlation_id) $query->orWhere('correlation_id', $interaction->correlation_id);
                if ($interaction->request_id) $query->orWhere('request_id', $interaction->request_id);
                if ($interaction->parent_interaction_id) $query->orWhere('parent_interaction_id', $interaction->parent_interaction_id);
                $query->orWhere('parent_interaction_id', $interaction->id);
            })
            ->latest('id')
            ->limit(40)
            ->get();

        $context = $this->resourceContext(collect([$interaction])->merge($related));

        return [
            'activity' => $this->payload($interaction, $context, true),
            'related' => $related->map(fn (Interaction $item) => $this->payload($item, $context))->values(),
            'session' => [
                'key' => $interaction->session_key,
                'correlation_id' => $interaction->correlation_id,
                'request_id' => $interaction->request_id,
                'related_count' => $related->count(),
            ],
        ];
    }

    private function query(array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = Interaction::query()->whereBetween('created_at', [$from, $to]);

        if (! empty($filters['user_id'])) $query->where('user_id', (int) $filters['user_id']);
        if (! empty($filters['app_id'])) $query->where('app_id', (int) $filters['app_id']);
        if (! empty($filters['type'])) $query->where('interaction_type', $filters['type']);
        if (! empty($filters['outcome'])) $query->where('outcome', $filters['outcome']);
        if (! empty($filters['severity'])) $query->where('severity', $filters['severity']);
        if (! empty($filters['environment'])) $query->where('environment', $filters['environment']);
        if (! empty($filters['method'])) $query->where('method', strtoupper($filters['method']));
        if (! empty($filters['entity_type'])) $query->where('entity_type', 'like', '%' . $filters['entity_type'] . '%');
        if (! empty($filters['entity_id'])) $query->where('entity_id', (int) $filters['entity_id']);
        if (! empty($filters['session_key'])) $query->where('session_key', $filters['session_key']);
        if (! empty($filters['correlation_id'])) $query->where('correlation_id', $filters['correlation_id']);
        if (! empty($filters['request_id'])) $query->where('request_id', $filters['request_id']);
        if (! empty($filters['source'])) $query->where('content->source_channel', $filters['source']);

        if (! empty($filters['establishment_id'])) {
            $id = (int) $filters['establishment_id'];
            $query->where(function ($nested) use ($id) {
                $nested->where(function ($direct) use ($id) {
                    $direct->where('entity_id', $id)
                        ->where(function ($type) {
                            $type->where('entity_type', 'Establishment')
                                ->orWhere('entity_type', 'App\\Models\\Establishment');
                        });
                })
                    ->orWhere('content->establishment_id', $id)
                    ->orWhere('content->metadata->establishment_id', $id);
            });
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhere('request_id', 'like', "%{$search}%")
                    ->orWhere('correlation_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('user_name', 'like', "%{$search}%"))
                    ->orWhereHas('application', fn ($app) => $app
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    private function window(array $filters): array
    {
        $now = CarbonImmutable::now();
        $range = $filters['range'] ?? self::DEFAULT_RANGE;

        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = ! empty($filters['from']) ? CarbonImmutable::parse($filters['from']) : $now->subDay();
            $to = ! empty($filters['to']) ? CarbonImmutable::parse($filters['to']) : $now;
        } else {
            $from = match ($range) {
                '1h' => $now->subHour(),
                '7d' => $now->subDays(7),
                '30d' => $now->subDays(30),
                '90d' => $now->subDays(90),
                default => $now->subDay(),
            };
            $to = $now;
        }

        if ($from->greaterThan($to)) [$from, $to] = [$to, $from];
        if ($from->diffInDays($to) > 90) $from = $to->subDays(90);

        return [$from, $to];
    }

    private function windowPayload(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'seconds' => max($from->diffInSeconds($to), 1),
        ];
    }

    private function listSummary(Builder $query): array
    {
        $base = clone $query;
        $total = (clone $base)->count();
        $errors = (clone $base)->where('outcome', 'error')->count();

        return [
            'total' => $total,
            'users' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
            'applications' => (clone $base)->whereNotNull('app_id')->distinct()->count('app_id'),
            'sessions' => (clone $base)->whereNotNull('session_key')->distinct()->count('session_key'),
            'errors' => $errors,
            'error_rate' => $total > 0 ? round(($errors / $total) * 100, 2) : 0.0,
        ];
    }

    private function timeline(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $hours = max($from->diffInHours($to), 1);
        $hourly = $hours <= 72;
        $driver = DB::connection()->getDriverName();
        $expression = match ($driver) {
            'pgsql' => $hourly ? "DATE_TRUNC('hour', created_at)" : "DATE_TRUNC('day', created_at)",
            'sqlite' => $hourly ? "strftime('%Y-%m-%d %H:00:00', created_at)" : "strftime('%Y-%m-%d 00:00:00', created_at)",
            default => $hourly ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')" : "DATE_FORMAT(created_at, '%Y-%m-%d 00:00:00')",
        };

        return (clone $query)
            ->reorder()
            ->selectRaw("{$expression} as bucket, COUNT(*) total, SUM(CASE WHEN outcome = 'error' THEN 1 ELSE 0 END) errors")
            ->groupByRaw($expression)
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'timestamp' => CarbonImmutable::parse($row->bucket)->toIso8601String(),
                'label' => CarbonImmutable::parse($row->bucket)->format($hourly ? 'd/m H:i' : 'd/m'),
                'total' => (int) $row->total,
                'errors' => (int) $row->errors,
            ]);
    }

    private function topApplications(Builder $query): Collection
    {
        $rows = (clone $query)->reorder()
            ->whereNotNull('app_id')
            ->selectRaw('app_id, COUNT(*) total, COUNT(DISTINCT user_id) users, SUM(CASE WHEN outcome = \'error\' THEN 1 ELSE 0 END) errors')
            ->groupBy('app_id')->orderByDesc('total')->limit(10)->get();
        $apps = Application::query()->whereIn('id', $rows->pluck('app_id'))->get(['id', 'name', 'slug', 'logo'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'application' => $apps->get($row->app_id),
            'total' => (int) $row->total,
            'users' => (int) $row->users,
            'errors' => (int) $row->errors,
        ]);
    }

    private function topTypes(Builder $query): Collection
    {
        return (clone $query)->reorder()
            ->selectRaw('interaction_type, COUNT(*) total, SUM(CASE WHEN outcome = \'error\' THEN 1 ELSE 0 END) errors')
            ->groupBy('interaction_type')->orderByDesc('total')->limit(12)->get()
            ->map(fn ($row) => ['type' => $row->interaction_type, 'total' => (int) $row->total, 'errors' => (int) $row->errors]);
    }

    private function topUsers(Builder $query): Collection
    {
        $rows = (clone $query)->reorder()->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) total, MAX(created_at) last_activity_at')
            ->groupBy('user_id')->orderByDesc('total')->limit(10)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'first_name', 'last_name', 'user_name', 'email', 'avatar'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'user' => $users->get($row->user_id),
            'total' => (int) $row->total,
            'last_activity_at' => $row->last_activity_at,
        ]);
    }

    private function topEstablishments(Builder $query): Collection
    {
        $rows = (clone $query)->reorder()
            ->whereNotNull('entity_id')
            ->where(function ($type) {
                $type->where('entity_type', 'Establishment')->orWhere('entity_type', 'App\\Models\\Establishment');
            })
            ->selectRaw('entity_id, COUNT(*) total, COUNT(DISTINCT user_id) users')
            ->groupBy('entity_id')->orderByDesc('total')->limit(10)->get();
        $establishments = Establishment::query()->whereIn('id', $rows->pluck('entity_id'))->get(['id', 'name', 'fantasy', 'app_id', 'city', 'uf'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'establishment' => $establishments->get($row->entity_id),
            'total' => (int) $row->total,
            'users' => (int) $row->users,
        ])->filter(fn ($row) => $row['establishment'] !== null)->values();
    }

    private function latestErrors(Builder $query): Collection
    {
        $rows = (clone $query)
            ->with(['user:id,first_name,last_name,user_name,email,avatar', 'application:id,name,slug,logo'])
            ->where(function ($error) {
                $error->where('outcome', 'error')
                    ->orWhere('interaction_type', 'like', '%error%')
                    ->orWhere('interaction_type', 'like', '%failed%');
            })
            ->latest('id')->limit(8)->get();
        $context = $this->resourceContext($rows);

        return $rows->map(fn (Interaction $row) => $this->payload($row, $context))->values();
    }

    private function resourceContext(Collection $rows): array
    {
        $establishmentIds = $this->establishmentIds($rows);
        $itemIds = $rows->filter(fn (Interaction $row) => $this->baseEntityType($row->entity_type) === 'Item')->pluck('entity_id')->filter()->unique();
        $items = Item::query()->whereIn('id', $itemIds)->get(['id', 'name', 'entity_id', 'entity_name', 'app_id'])->keyBy('id');

        foreach ($items as $item) {
            if (strtolower((string) $item->entity_name) === 'establishment' && $item->entity_id) $establishmentIds->push((int) $item->entity_id);
        }

        return [
            'establishments' => Establishment::query()->whereIn('id', $establishmentIds->unique()->values())->get(['id', 'name', 'fantasy', 'app_id', 'city', 'uf'])->keyBy('id'),
            'items' => $items,
        ];
    }

    private function establishmentIds(Collection $rows): Collection
    {
        return $rows->flatMap(function (Interaction $row) {
            $content = is_array($row->content) ? $row->content : [];
            $ids = [
                data_get($content, 'establishment_id'),
                data_get($content, 'metadata.establishment_id'),
                data_get($content, 'context.establishment_id'),
            ];
            if ($this->baseEntityType($row->entity_type) === 'Establishment') $ids[] = $row->entity_id;
            return $ids;
        })->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function payload(Interaction $item, array $context, bool $full = false): array
    {
        $content = is_array($item->content) ? $item->content : [];
        $sanitized = $this->sanitize($content);
        $entityType = $this->baseEntityType($item->entity_type);
        $establishmentId = $this->establishmentIdFor($item, $content, $context);
        $establishment = $establishmentId ? $context['establishments']->get($establishmentId) : null;
        $entityName = $item->name;

        if ($entityType === 'Item' && $item->entity_id) {
            $entityName = $context['items']->get($item->entity_id)?->name ?: $entityName;
        } elseif ($entityType === 'Establishment' && $item->entity_id) {
            $entityName = $context['establishments']->get($item->entity_id)?->fantasy ?: $context['establishments']->get($item->entity_id)?->name ?: $entityName;
        }

        $payload = [
            'id' => $item->id,
            'type' => $item->interaction_type,
            'name' => $item->name ?: ucfirst(str_replace('_', ' ', (string) $item->interaction_type)),
            'outcome' => $item->outcome ?: 'success',
            'severity' => $item->severity ?: 'normal',
            'environment' => $item->environment,
            'created_at' => $item->created_at?->toIso8601String(),
            'route' => $item->route,
            'method' => $item->method,
            'source' => data_get($content, 'source_channel') ?: (str_starts_with((string) $item->interaction_type, 'frontend_') ? 'frontend' : 'domain'),
            'page' => data_get($content, 'frontend_page') ?: data_get($content, 'page') ?: data_get($content, 'metadata.page'),
            'http_status' => $this->numeric(data_get($content, 'status') ?: data_get($content, 'metadata.status')),
            'duration_ms' => $this->durationMs($content),
            'session_key' => $item->session_key,
            'request_id' => $item->request_id,
            'correlation_id' => $item->correlation_id,
            'parent_interaction_id' => $item->parent_interaction_id,
            'user' => $item->user,
            'application' => $item->application,
            'entity' => [
                'type' => $entityType ?: null,
                'id' => $item->entity_id,
                'name' => $entityName,
            ],
            'establishment' => $establishment,
            'device' => $this->device(data_get($content, 'user_agent')),
            'network_fingerprint' => data_get($content, 'ip_hash') ?: $this->networkFingerprint(data_get($content, 'ip')),
            'origin' => data_get($content, 'origin'),
            'referer' => data_get($content, 'referer'),
            'label' => data_get($content, 'label'),
            'target' => data_get($content, 'target'),
        ];

        if ($full) {
            $payload['content'] = $sanitized;
            $payload['comment'] = $item->comment;
            $payload['parent'] = $item->parentInteraction;
            $payload['children'] = $item->relatedInteractions;
        }

        return $payload;
    }

    private function establishmentIdFor(Interaction $item, array $content, array $context): ?int
    {
        $direct = data_get($content, 'establishment_id') ?: data_get($content, 'metadata.establishment_id') ?: data_get($content, 'context.establishment_id');
        if (is_numeric($direct) && (int) $direct > 0) return (int) $direct;
        if ($this->baseEntityType($item->entity_type) === 'Establishment' && $item->entity_id) return (int) $item->entity_id;

        if ($this->baseEntityType($item->entity_type) === 'Item' && $item->entity_id) {
            $entityId = $context['items']->get($item->entity_id)?->entity_id;
            if ($entityId) return (int) $entityId;
        }

        return null;
    }

    private function sourceBreakdown(Collection $sample): Collection
    {
        return $sample->map(function (Interaction $row) {
            $content = is_array($row->content) ? $row->content : [];
            return data_get($content, 'source_channel') ?: (str_starts_with((string) $row->interaction_type, 'frontend_') ? 'frontend' : 'domain');
        })->filter()->countBy()->sortDesc()->map(fn ($total, $source) => ['source' => $source, 'total' => $total])->values();
    }

    private function deviceBreakdown(Collection $sample): Collection
    {
        return $sample->map(function (Interaction $row) {
            $content = is_array($row->content) ? $row->content : [];
            return $this->device(data_get($content, 'user_agent'))['label'] ?? null;
        })->filter()->countBy()->sortDesc()->take(8)->map(fn ($total, $label) => ['label' => $label, 'total' => $total])->values();
    }

    private function device(?string $agent): array
    {
        if (! $agent) return ['browser' => null, 'os' => null, 'device' => null, 'label' => 'Não identificado'];
        $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Safari/') ? 'Safari' : 'Outro')));
        $os = str_contains($agent, 'Android') ? 'Android' : ((str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) ? 'iOS' : (str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Macintosh') ? 'macOS' : (str_contains($agent, 'Linux') ? 'Linux' : 'Outro'))));
        $device = (str_contains($agent, 'Mobile') || str_contains($agent, 'Android') || str_contains($agent, 'iPhone')) ? 'Mobile' : (str_contains($agent, 'iPad') ? 'Tablet' : 'Desktop');
        return ['browser' => $browser, 'os' => $os, 'device' => $device, 'label' => "{$browser} · {$os} · {$device}"];
    }

    private function durationMs(array $content): ?float
    {
        foreach (['duration_ms', 'response_time_ms', 'latency_ms', 'metadata.duration_ms', 'metadata.response_time_ms', 'metadata.latency_ms', 'metadata.performance.duration_ms'] as $key) {
            $value = data_get($content, $key);
            if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 3_600_000) return round((float) $value, 1);
        }
        return null;
    }

    private function percentile(Collection $values, int $percentile): ?float
    {
        if ($values->isEmpty()) return null;
        $index = (int) ceil(($percentile / 100) * $values->count()) - 1;
        return round((float) $values->get(max(min($index, $values->count() - 1), 0)), 1);
    }

    private function health(float $errorRate, int $critical, ?float $p95): array
    {
        $score = 100;
        $score -= min((int) round($errorRate * 3), 45);
        $score -= min($critical * 4, 30);
        if ($p95 !== null && $p95 > 2500) $score -= 15;
        elseif ($p95 !== null && $p95 > 1200) $score -= 7;
        $score = max($score, 0);
        $status = $score < 60 ? 'critical' : ($score < 85 ? 'degraded' : 'operational');

        return [
            'status' => $status,
            'score' => $score,
            'label' => match ($status) {
                'critical' => 'Crítico',
                'degraded' => 'Atenção',
                default => 'Operacional',
            },
        ];
    }

    private function sanitize(mixed $value, ?string $key = null, int $depth = 0): mixed
    {
        if ($depth > 5) return '[TRUNCATED]';
        $normalizedKey = strtolower((string) $key);
        if ($normalizedKey !== '' && preg_match('/password|token|authorization|cookie|secret|card|cvv|cvc|cpf|document|refresh|bearer|code/', $normalizedKey)) return '[REDACTED]';
        if ($normalizedKey === 'ip') return '[MASKED]';

        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 80, true) as $childKey => $childValue) {
                $result[$childKey] = $this->sanitize($childValue, (string) $childKey, $depth + 1);
            }
            return $result;
        }

        if (is_string($value)) return mb_substr($value, 0, 1500);
        return $value;
    }

    private function networkFingerprint(?string $ip): ?string
    {
        if (! $ip) return null;
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function baseEntityType(?string $type): ?string
    {
        if (! $type) return null;
        $parts = preg_split('/[\\\\\/]+/', $type);
        return $parts ? end($parts) : $type;
    }

    private function numeric(mixed $value): int|float|null
    {
        if (! is_numeric($value)) return null;
        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }
}
