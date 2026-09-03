<?php

namespace App\Domain\Discovery\Services;

use App\Models\DiscoveryEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DiscoveryLearningService
{
    public function __construct(private readonly DiscoverySearchIndexService $searchIndex)
    {
    }

    public function recommendations(?string $sessionId, int $limit = 8, ?int $applicationId = null): array
    {
        $recent = $sessionId
            ? DiscoveryEvent::query()
                ->where('session_id', $sessionId)
                ->when($applicationId, fn ($query) => $query->where('application_id', $applicationId))
                ->latest('occurred_at')
                ->limit(25)
                ->get()
            : collect();

        $seenKeys = $recent
            ->filter(fn (DiscoveryEvent $event) => $event->entity_type && $event->entity_id)
            ->map(function (DiscoveryEvent $event) {
                $type = $event->entity_type === 'product' ? 'item' : $event->entity_type;
                return $type . ':' . $event->entity_id;
            })
            ->unique()
            ->values();

        $terms = $recent->flatMap(function (DiscoveryEvent $event) {
            $metadata = $event->metadata ?: [];
            return array_filter([
                $metadata['term'] ?? null,
                $metadata['category'] ?? null,
                $event->entity_type,
            ]);
        })->map(fn ($value) => $this->normalize((string) $value))
            ->filter()
            ->unique()
            ->take(8)
            ->values();

        $popularEntities = DiscoveryEvent::query()
            ->whereIn('event_type', ['product_view', 'content_view', 'establishment_view'])
            ->where('occurred_at', '>=', now()->subDays(30))
            ->when($applicationId, fn ($query) => $query->where('application_id', $applicationId))
            ->selectRaw('entity_type, entity_id, COUNT(*) total')
            ->groupBy('entity_type', 'entity_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        return $this->searchIndex->recommendations(
            $terms,
            $seenKeys,
            $popularEntities,
            $applicationId,
            $limit
        );
    }

    public function resolveExperiment(string $surface, string $sessionId, ?int $applicationId = null): ?array
    {
        $experiment = DB::table('discovery_experiments')
            ->where('surface', $surface)
            ->where('status', 'running')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($applicationId, fn ($query) => $query->where(
                fn ($scope) => $scope->whereNull('application_id')->orWhere('application_id', $applicationId)
            ))
            ->orderByDesc('application_id')
            ->first();

        if (! $experiment) {
            return null;
        }

        $bucket = hexdec(substr(sha1($experiment->key . ':' . $sessionId), 0, 6)) % 100;
        if ($bucket >= (int) $experiment->allocation_percent) {
            return null;
        }

        $variants = collect(json_decode($experiment->variants, true) ?: [])->values();
        if ($variants->isEmpty()) {
            return null;
        }

        $variant = $variants[
            hexdec(substr(sha1($sessionId . ':' . $experiment->key), 6, 6)) % $variants->count()
        ];

        return [
            'experiment_id' => $experiment->id,
            'key' => $experiment->key,
            'surface' => $experiment->surface,
            'variant_key' => $variant['key'] ?? 'variant-' . ($bucket % $variants->count()),
            'payload' => $variant['payload'] ?? [],
            'goal_event' => $experiment->goal_event,
        ];
    }

    public function experimentEvent(
        int $experimentId,
        string $variantKey,
        string $sessionId,
        string $eventType,
        ?float $value = null,
        array $metadata = []
    ): void {
        if (
            $eventType === 'exposure'
            && DB::table('discovery_experiment_events')
                ->where('experiment_id', $experimentId)
                ->where('variant_key', $variantKey)
                ->where('session_id', $sessionId)
                ->where('event_type', 'exposure')
                ->exists()
        ) {
            return;
        }

        DB::table('discovery_experiment_events')->insert([
            'experiment_id' => $experimentId,
            'variant_key' => $variantKey,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'conversion_value' => $value,
            'metadata' => $metadata
                ? json_encode(Arr::only($metadata, ['path', 'source', 'goal']))
                : null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function experimentStats(): array
    {
        return DB::table('discovery_experiments')->orderByDesc('updated_at')->get()->map(function ($experiment) {
            $events = DB::table('discovery_experiment_events')
                ->where('experiment_id', $experiment->id)
                ->get();

            $variants = collect(json_decode($experiment->variants, true) ?: [])->map(function ($variant) use ($events) {
                $key = $variant['key'] ?? 'variant';
                $exposures = $events->where('variant_key', $key)
                    ->where('event_type', 'exposure')
                    ->pluck('session_id')->unique()->count();
                $conversions = $events->where('variant_key', $key)
                    ->where('event_type', 'conversion')
                    ->pluck('session_id')->unique()->count();

                return $variant + [
                    'exposures' => $exposures,
                    'conversions' => $conversions,
                    'rate' => $exposures ? round($conversions / $exposures * 100, 2) : 0,
                ];
            })->values();

            return [
                'id' => $experiment->id,
                'key' => $experiment->key,
                'name' => $experiment->name,
                'surface' => $experiment->surface,
                'status' => $experiment->status,
                'goal_event' => $experiment->goal_event,
                'allocation_percent' => $experiment->allocation_percent,
                'variants' => $variants,
                'starts_at' => $experiment->starts_at,
                'ends_at' => $experiment->ends_at,
            ];
        })->all();
    }

    public function opportunities(int $days = 30): array
    {
        $from = now()->subDays($days)->toDateString();
        $rows = DB::table('search_performance_records')
            ->where('measured_on', '>=', $from)
            ->selectRaw('provider, query, page, SUM(clicks) clicks, SUM(impressions) impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 1.0 / SUM(impressions) ELSE 0 END ctr, AVG(position) position')
            ->groupBy('provider', 'query', 'page')
            ->orderByDesc('impressions')
            ->limit(500)
            ->get();

        return $rows->map(function ($row) {
            $reason = null;
            $priority = 0;

            if ($row->impressions >= 100 && $row->ctr < 0.02) {
                $reason = 'Muitas impressões e CTR baixo: testar título/descrição e intenção da página.';
                $priority += 40;
            }
            if ($row->position >= 4 && $row->position <= 20 && $row->impressions >= 30) {
                $reason = $reason ?: 'Consulta próxima da primeira página: reforçar conteúdo e links internos.';
                $priority += 30;
            }
            if (! $row->page && $row->impressions >= 20) {
                $reason = 'Consulta relevante sem página associada: avaliar landing ou conteúdo específico.';
                $priority += 35;
            }

            return $reason ? [
                'provider' => $row->provider,
                'query' => $row->query,
                'page' => $row->page,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'ctr' => round((float) $row->ctr * 100, 2),
                'position' => round((float) $row->position, 1),
                'priority' => $priority,
                'reason' => $reason,
            ] : null;
        })->filter()->sortByDesc('priority')->take(100)->values()->all();
    }

    public function attribution(int $days = 30): array
    {
        return DiscoveryEvent::query()
            ->where('event_type', 'conversion')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->get()
            ->groupBy(fn ($event) => implode('|', [
                $event->source ?: 'direto',
                $event->metadata['term'] ?? '',
                $event->metadata['campaign'] ?? '',
            ]))
            ->map(function ($group, $key) {
                [$source, $term, $campaign] = array_pad(explode('|', $key), 3, '');
                return [
                    'source' => $source,
                    'term' => $term ?: null,
                    'campaign' => $campaign ?: null,
                    'conversions' => $group->count(),
                    'sessions' => $group->pluck('session_id')->filter()->unique()->count(),
                ];
            })->sortByDesc('conversions')->values()->take(100)->all();
    }

    public function accessibilitySummary(int $days = 30): array
    {
        $rows = DB::table('experience_audits')
            ->where('audited_at', '>=', now()->subDays($days))
            ->get();

        return [
            'samples' => $rows->count(),
            'average_score' => $rows->count() ? round((float) $rows->avg('score'), 1) : null,
            'pages' => $rows->groupBy('path')->map(fn ($group, $path) => [
                'path' => $path,
                'samples' => $group->count(),
                'score' => round((float) $group->avg('score'), 1),
                'issues' => $group->sum(fn ($row) => count(json_decode($row->issues ?: '[]', true))),
            ])->sortBy('score')->values()->take(50)->all(),
        ];
    }

    public function healthSummary(): array
    {
        $latest = DB::table('public_page_checks')
            ->orderByDesc('checked_at')
            ->limit(500)
            ->get()
            ->unique('path')
            ->values();

        return [
            'pages' => $latest->count(),
            'healthy' => $latest->where('ok', 1)->count(),
            'unhealthy' => $latest->where('ok', 0)->count(),
            'checks' => $latest->take(100)->map(fn ($row) => [
                'path' => $row->path,
                'ok' => (bool) $row->ok,
                'status_code' => $row->status_code,
                'canonical_ok' => (bool) $row->canonical_ok,
                'schema_ok' => (bool) $row->schema_ok,
                'image_errors' => $row->image_errors,
                'response_ms' => $row->response_ms,
                'issues' => json_decode($row->issues ?: '[]', true),
                'checked_at' => $row->checked_at,
            ])->all(),
        ];
    }

    public function monitorPublicPages(int $limit = 100): int
    {
        $this->searchIndex->ensureBuilt();
        $origin = rtrim((string) config(
            'app.frontend_url',
            env('FRONTEND_URL', 'https://petertecnet.com.br')
        ), '/');
        $paths = collect(['/', '/blog', '/buscar'])
            ->merge(DB::table('discovery_search_documents')->orderByDesc('boost')->limit($limit)->pluck('url'))
            ->unique()
            ->take($limit);

        foreach ($paths as $path) {
            $started = microtime(true);
            $issues = [];
            $status = null;
            $html = '';

            try {
                $response = Http::timeout(12)->retry(1, 250)->get(
                    Str::startsWith($path, 'http') ? $path : $origin . $path
                );
                $status = $response->status();
                $html = $response->body();
            } catch (\Throwable) {
                $issues[] = 'request_failed';
            }

            $canonicalOk = $html
                ? (bool) preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+>/i', $html)
                : false;
            $schemaOk = $html ? str_contains($html, 'application/ld+json') : false;
            $imageErrors = 0;

            if ($html && preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
                foreach (array_slice(array_unique($matches[1]), 0, 8) as $src) {
                    try {
                        $url = Str::startsWith($src, 'http') ? $src : $origin . '/' . ltrim($src, '/');
                        if (! Http::timeout(5)->head($url)->successful()) {
                            $imageErrors++;
                        }
                    } catch (\Throwable) {
                        $imageErrors++;
                    }
                }
            }

            if (! $status || $status >= 400) {
                $issues[] = 'http_status';
            }
            if (! $canonicalOk) {
                $issues[] = 'canonical_missing';
            }
            if (! $schemaOk) {
                $issues[] = 'schema_missing';
            }
            if ($imageErrors) {
                $issues[] = 'broken_images';
            }

            DB::table('public_page_checks')->insert([
                'path' => $path,
                'status_code' => $status,
                'ok' => $status && $status < 400 && $canonicalOk && $schemaOk && $imageErrors === 0,
                'canonical_ok' => $canonicalOk,
                'schema_ok' => $schemaOk,
                'image_errors' => $imageErrors,
                'response_ms' => (int) round((microtime(true) - $started) * 1000),
                'issues' => json_encode($issues),
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $paths->count();
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
