<?php

namespace App\Domain\Discovery\Services;

use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\DiscoveryEvent;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DiscoveryLearningService
{
    public function rebuildIndex(): int
    {
        $rows = collect();

        Application::query()->where('is_active', true)->get()->each(function ($app) use ($rows) {
            $rows->push($this->document('application', $app->id, $app->id, $app->name, $app->description, null, null, '/plataformas/' . $app->slug, 30, [
                'slug' => $app->slug,
            ]));
        });

        ContentEntry::query()->published()->limit(4000)->get()->each(function ($entry) use ($rows) {
            $rows->push($this->document('content', $entry->id, $entry->application_id, $entry->title, $entry->excerpt ?: Str::limit(strip_tags((string) $entry->content), 320), $entry->category, null, '/blog/' . $entry->slug, 24, [
                'cluster' => $entry->cluster,
                'tags' => $entry->tags,
                'search_intent' => $entry->search_intent,
            ]));
        });

        Establishment::query()->where('is_cancelled', false)->where('is_published', true)->limit(5000)->get()->each(function ($row) use ($rows) {
            $title = $row->fantasy ?: $row->name;
            $rows->push($this->document('establishment', $row->id, $row->app_id, $title, $row->description, $row->category ?: $row->type, $row->city, '/empresas/' . $row->slug, 22, [
                'uf' => $row->uf,
            ]));
        });

        Item::query()->with('establishment:id,app_id,name,fantasy,slug,city,uf,is_published,is_cancelled')
            ->where('status', true)->where('entity_name', 'establishment')
            ->whereHas('establishment', fn ($query) => $query->where('is_cancelled', false)->where('is_published', true))
            ->limit(12000)->get()->each(function ($row) use ($rows) {
                $establishment = $row->establishment;
                $rows->push($this->document('item', $row->id, $row->app_id ?: $establishment?->app_id, $row->name, $row->description, $row->category ?: $row->type, $establishment?->city, '/solucoes/' . ($row->slug ?: $row->id), 28, [
                    'price' => $row->price,
                    'brand' => $row->brand,
                    'establishment' => $establishment ? [
                        'id' => $establishment->id,
                        'name' => $establishment->fantasy ?: $establishment->name,
                        'slug' => $establishment->slug,
                        'uf' => $establishment->uf,
                    ] : null,
                ]));
            });

        DB::transaction(function () use ($rows) {
            DB::table('discovery_search_documents')->delete();
            $rows->chunk(500)->each(fn ($chunk) => DB::table('discovery_search_documents')->insert($chunk->all()));
        });
        Cache::tags(['discovery-search'])->flush();

        return $rows->count();
    }

    public function rankedSearch(string $term, ?string $city = null, ?int $applicationId = null, int $limit = 8): array
    {
        $normalized = $this->normalize($term);
        $tokens = collect(preg_split('/\s+/', $normalized))->filter(fn ($token) => mb_strlen($token) >= 2)->unique()->values();
        $key = 'discovery-search:' . sha1(json_encode([$normalized, $city, $applicationId, $limit]));

        return Cache::tags(['discovery-search'])->remember($key, 180, function () use ($normalized, $tokens, $city, $applicationId, $limit) {
            $query = DB::table('discovery_search_documents');
            if ($applicationId) $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhere('application_id', $applicationId));
            if ($city) $query->where(fn ($scope) => $scope->whereNull('city')->orWhere('city', 'like', '%' . $city . '%'));
            $query->where(function ($scope) use ($tokens, $normalized) {
                $scope->where('title', 'like', '%' . $normalized . '%')->orWhere('search_text', 'like', '%' . $normalized . '%');
                foreach ($tokens as $token) {
                    $scope->orWhere('title', 'like', '%' . $token . '%')->orWhere('search_text', 'like', '%' . $token . '%');
                }
            });

            $documents = $query->limit(250)->get()->map(function ($row) use ($normalized, $tokens, $city) {
                $haystack = $this->normalize($row->title . ' ' . $row->search_text . ' ' . $row->category . ' ' . $row->city);
                $title = $this->normalize($row->title);
                $score = (int) $row->boost;
                if ($title === $normalized) $score += 80;
                elseif (str_starts_with($title, $normalized)) $score += 55;
                elseif (str_contains($title, $normalized)) $score += 38;
                if (str_contains($haystack, $normalized)) $score += 24;
                foreach ($tokens as $token) {
                    if (str_contains($title, $token)) $score += 12;
                    elseif (str_contains($haystack, $token)) $score += 5;
                    else {
                        foreach (preg_split('/\s+/', $title) as $word) {
                            if (mb_strlen($word) > 3 && levenshtein($token, $word) <= 1) { $score += 4; break; }
                        }
                    }
                }
                if ($city && $row->city && Str::contains(Str::lower($row->city), Str::lower($city))) $score += 18;
                $row->score = $score;
                $row->metadata = json_decode($row->metadata ?: 'null', true);
                return $row;
            })->sortByDesc('score')->take($limit * 4)->values();

            return [
                'query' => $normalized,
                'count' => $documents->count(),
                'results' => $documents->groupBy('document_type')->map(fn ($group) => $group->take($limit)->map(fn ($row) => [
                    'type' => $row->document_type,
                    'id' => $row->document_id,
                    'title' => $row->title,
                    'description' => $row->summary,
                    'category' => $row->category,
                    'location' => $row->city,
                    'url' => $row->url,
                    'score' => $row->score,
                    'application_id' => $row->application_id,
                    'metadata' => $row->metadata,
                ])->values())->all(),
            ];
        });
    }

    public function recommendations(?string $sessionId, int $limit = 8): array
    {
        $recent = $sessionId ? DiscoveryEvent::query()->where('session_id', $sessionId)->latest('occurred_at')->limit(25)->get() : collect();
        $seen = $recent->pluck('entity_id')->filter()->map(fn ($id) => (string) $id)->unique();
        $terms = $recent->flatMap(function ($event) {
            $metadata = $event->metadata ?: [];
            return array_filter([$metadata['term'] ?? null, $metadata['category'] ?? null, $event->entity_type]);
        })->map(fn ($value) => $this->normalize((string) $value))->filter()->unique()->take(8);

        if ($terms->isNotEmpty() && DB::table('discovery_search_documents')->exists()) {
            $query = DB::table('discovery_search_documents')->whereNotIn(DB::raw('CAST(document_id AS CHAR)'), $seen->all());
            $query->where(function ($scope) use ($terms) {
                foreach ($terms as $term) $scope->orWhere('search_text', 'like', '%' . $term . '%')->orWhere('category', 'like', '%' . $term . '%');
            });
            $rows = $query->orderByDesc('boost')->limit($limit)->get();
        } else {
            $popularIds = DiscoveryEvent::query()->whereIn('event_type', ['product_view', 'content_view', 'establishment_view'])->where('occurred_at', '>=', now()->subDays(30))
                ->selectRaw('entity_type, entity_id, COUNT(*) total')->groupBy('entity_type', 'entity_id')->orderByDesc('total')->limit(30)->get();
            $rows = collect();
            foreach ($popularIds as $popular) {
                $type = match ($popular->entity_type) { 'product' => 'item', default => $popular->entity_type };
                $row = DB::table('discovery_search_documents')->where('document_type', $type)->where('document_id', $popular->entity_id)->first();
                if ($row) $rows->push($row);
                if ($rows->count() >= $limit) break;
            }
        }

        return collect($rows ?? [])->map(fn ($row) => [
            'type' => $row->document_type,
            'id' => $row->document_id,
            'title' => $row->title,
            'description' => $row->summary,
            'category' => $row->category,
            'location' => $row->city,
            'url' => $row->url,
            'application_id' => $row->application_id,
        ])->values()->all();
    }

    public function resolveExperiment(string $surface, string $sessionId, ?int $applicationId = null): ?array
    {
        $experiment = DB::table('discovery_experiments')->where('surface', $surface)->where('status', 'running')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when($applicationId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhere('application_id', $applicationId)))
            ->orderByDesc('application_id')->first();
        if (! $experiment) return null;
        $bucket = hexdec(substr(sha1($experiment->key . ':' . $sessionId), 0, 6)) % 100;
        if ($bucket >= (int) $experiment->allocation_percent) return null;
        $variants = collect(json_decode($experiment->variants, true) ?: [])->values();
        if ($variants->isEmpty()) return null;
        $variant = $variants[hexdec(substr(sha1($sessionId . ':' . $experiment->key), 6, 6)) % $variants->count()];
        return [
            'experiment_id' => $experiment->id,
            'key' => $experiment->key,
            'surface' => $experiment->surface,
            'variant_key' => $variant['key'] ?? 'variant-' . ($bucket % $variants->count()),
            'payload' => $variant['payload'] ?? [],
            'goal_event' => $experiment->goal_event,
        ];
    }

    public function experimentEvent(int $experimentId, string $variantKey, string $sessionId, string $eventType, ?float $value = null, array $metadata = []): void
    {
        if ($eventType === 'exposure' && DB::table('discovery_experiment_events')->where('experiment_id', $experimentId)->where('variant_key', $variantKey)->where('session_id', $sessionId)->where('event_type', 'exposure')->exists()) return;
        DB::table('discovery_experiment_events')->insert([
            'experiment_id' => $experimentId,
            'variant_key' => $variantKey,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'conversion_value' => $value,
            'metadata' => $metadata ? json_encode(Arr::only($metadata, ['path', 'source', 'goal'])) : null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function experimentStats(): array
    {
        return DB::table('discovery_experiments')->orderByDesc('updated_at')->get()->map(function ($experiment) {
            $events = DB::table('discovery_experiment_events')->where('experiment_id', $experiment->id)->get();
            $variants = collect(json_decode($experiment->variants, true) ?: [])->map(function ($variant) use ($events) {
                $key = $variant['key'] ?? 'variant';
                $exposures = $events->where('variant_key', $key)->where('event_type', 'exposure')->pluck('session_id')->unique()->count();
                $conversions = $events->where('variant_key', $key)->where('event_type', 'conversion')->pluck('session_id')->unique()->count();
                return $variant + ['exposures' => $exposures, 'conversions' => $conversions, 'rate' => $exposures ? round($conversions / $exposures * 100, 2) : 0];
            })->values();
            return [
                'id' => $experiment->id, 'key' => $experiment->key, 'name' => $experiment->name, 'surface' => $experiment->surface,
                'status' => $experiment->status, 'goal_event' => $experiment->goal_event, 'allocation_percent' => $experiment->allocation_percent,
                'variants' => $variants, 'starts_at' => $experiment->starts_at, 'ends_at' => $experiment->ends_at,
            ];
        })->all();
    }

    public function opportunities(int $days = 30): array
    {
        $from = now()->subDays($days)->toDateString();
        $rows = DB::table('search_performance_records')->where('measured_on', '>=', $from)
            ->selectRaw('provider, query, page, SUM(clicks) clicks, SUM(impressions) impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 1.0 / SUM(impressions) ELSE 0 END ctr, AVG(position) position')
            ->groupBy('provider', 'query', 'page')->orderByDesc('impressions')->limit(500)->get();
        return $rows->map(function ($row) {
            $reason = null; $priority = 0;
            if ($row->impressions >= 100 && $row->ctr < 0.02) { $reason = 'Muitas impressões e CTR baixo: testar título/descrição e intenção da página.'; $priority += 40; }
            if ($row->position >= 4 && $row->position <= 20 && $row->impressions >= 30) { $reason = $reason ?: 'Consulta próxima da primeira página: reforçar conteúdo e links internos.'; $priority += 30; }
            if (! $row->page && $row->impressions >= 20) { $reason = 'Consulta relevante sem página associada: avaliar landing ou conteúdo específico.'; $priority += 35; }
            return $reason ? ['provider' => $row->provider, 'query' => $row->query, 'page' => $row->page, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions, 'ctr' => round((float) $row->ctr * 100, 2), 'position' => round((float) $row->position, 1), 'priority' => $priority, 'reason' => $reason] : null;
        })->filter()->sortByDesc('priority')->take(100)->values()->all();
    }

    public function attribution(int $days = 30): array
    {
        return DiscoveryEvent::query()->where('event_type', 'conversion')->where('occurred_at', '>=', now()->subDays($days))->get()
            ->groupBy(fn ($event) => implode('|', [$event->source ?: 'direto', $event->metadata['term'] ?? '', $event->metadata['campaign'] ?? '']))
            ->map(function ($group, $key) {
                [$source, $term, $campaign] = array_pad(explode('|', $key), 3, '');
                return ['source' => $source, 'term' => $term ?: null, 'campaign' => $campaign ?: null, 'conversions' => $group->count(), 'sessions' => $group->pluck('session_id')->filter()->unique()->count()];
            })->sortByDesc('conversions')->values()->take(100)->all();
    }

    public function accessibilitySummary(int $days = 30): array
    {
        $rows = DB::table('experience_audits')->where('audited_at', '>=', now()->subDays($days))->get();
        return [
            'samples' => $rows->count(),
            'average_score' => $rows->count() ? round((float) $rows->avg('score'), 1) : null,
            'pages' => $rows->groupBy('path')->map(fn ($group, $path) => ['path' => $path, 'samples' => $group->count(), 'score' => round((float) $group->avg('score'), 1), 'issues' => $group->sum(fn ($row) => count(json_decode($row->issues ?: '[]', true)))])->sortBy('score')->values()->take(50)->all(),
        ];
    }

    public function healthSummary(): array
    {
        $latest = DB::table('public_page_checks')->orderByDesc('checked_at')->limit(500)->get()->unique('path')->values();
        return [
            'pages' => $latest->count(),
            'healthy' => $latest->where('ok', 1)->count(),
            'unhealthy' => $latest->where('ok', 0)->count(),
            'checks' => $latest->take(100)->map(fn ($row) => ['path' => $row->path, 'ok' => (bool) $row->ok, 'status_code' => $row->status_code, 'canonical_ok' => (bool) $row->canonical_ok, 'schema_ok' => (bool) $row->schema_ok, 'image_errors' => $row->image_errors, 'response_ms' => $row->response_ms, 'issues' => json_decode($row->issues ?: '[]', true), 'checked_at' => $row->checked_at])->all(),
        ];
    }

    public function monitorPublicPages(int $limit = 100): int
    {
        $origin = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'https://petertecnet.com.br')), '/');
        $paths = collect(['/','/blog','/buscar'])
            ->merge(DB::table('discovery_search_documents')->orderByDesc('boost')->limit($limit)->pluck('url'))->unique()->take($limit);
        foreach ($paths as $path) {
            $started = microtime(true); $issues = []; $status = null; $html = '';
            try {
                $response = Http::timeout(12)->retry(1, 250)->get(Str::startsWith($path, 'http') ? $path : $origin . $path);
                $status = $response->status(); $html = $response->body();
            } catch (\Throwable $e) { $issues[] = 'request_failed'; }
            $canonicalOk = $html ? (bool) preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+>/i', $html) : false;
            $schemaOk = $html ? str_contains($html, 'application/ld+json') : false;
            $imageErrors = 0;
            if ($html && preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
                foreach (array_slice(array_unique($matches[1]), 0, 8) as $src) {
                    try { if (! Http::timeout(5)->head(Str::startsWith($src, 'http') ? $src : $origin . '/' . ltrim($src, '/'))->successful()) $imageErrors++; } catch (\Throwable) { $imageErrors++; }
                }
            }
            if (! $status || $status >= 400) $issues[] = 'http_status';
            if (! $canonicalOk) $issues[] = 'canonical_missing';
            if (! $schemaOk) $issues[] = 'schema_missing';
            if ($imageErrors) $issues[] = 'broken_images';
            DB::table('public_page_checks')->insert([
                'path' => $path, 'status_code' => $status, 'ok' => $status && $status < 400 && $canonicalOk && $schemaOk && $imageErrors === 0,
                'canonical_ok' => $canonicalOk, 'schema_ok' => $schemaOk, 'image_errors' => $imageErrors,
                'response_ms' => (int) round((microtime(true) - $started) * 1000), 'issues' => json_encode($issues), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $paths->count();
    }

    private function document(string $type, int $id, ?int $applicationId, string $title, ?string $summary, ?string $category, ?string $city, string $url, int $boost, array $metadata): array
    {
        $text = implode(' ', array_filter([$title, $summary, $category, $city, json_encode($metadata, JSON_UNESCAPED_UNICODE)]));
        return ['application_id' => $applicationId, 'document_type' => $type, 'document_id' => $id, 'title' => $title, 'summary' => $summary, 'search_text' => $this->normalize($text), 'category' => $category, 'city' => $city, 'url' => $url, 'boost' => $boost, 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
