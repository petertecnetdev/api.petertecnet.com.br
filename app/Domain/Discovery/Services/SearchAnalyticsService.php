<?php

namespace App\Domain\Discovery\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SearchAnalyticsService
{
    public function logSearch(
        int $appId,
        ?int $userId,
        ?string $sessionKey,
        array $parsed,
        string $type,
        int $resultCount,
        string $source = 'global'
    ): ?int {
        if (! Schema::hasTable('search_queries')) {
            return null;
        }

        $normalized = (string) ($parsed['normalized'] ?? '');
        $filters = array_filter(
            (array) ($parsed['filters'] ?? []),
            static fn ($value) => $value !== null && $value !== ''
        );

        return DB::table('search_queries')->insertGetId([
            'app_id' => $appId,
            'user_id' => $userId,
            'session_key' => $sessionKey ?: null,
            'query' => mb_substr((string) ($parsed['raw'] ?? ''), 0, 160),
            'normalized_query' => mb_substr($normalized, 0, 160),
            'query_hash' => hash('sha256', $normalized),
            'search_type' => $type,
            'city' => $filters['city'] ?? null,
            'uf' => $filters['uf'] ?? null,
            'filters' => $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
            'result_count' => max(0, min(65535, $resultCount)),
            'zero_result' => $resultCount === 0,
            'source' => $source,
            'created_at' => now(),
        ]);
    }

    public function logClick(
        int $appId,
        ?int $userId,
        ?int $searchQueryId,
        string $targetType,
        int $targetId,
        ?int $position,
        bool $sponsored
    ): ?int {
        if (! Schema::hasTable('search_clicks')) {
            return null;
        }

        $id = DB::table('search_clicks')->insertGetId([
            'search_query_id' => $searchQueryId,
            'app_id' => $appId,
            'user_id' => $userId,
            'target_type' => mb_substr($targetType, 0, 40),
            'target_id' => $targetId,
            'position' => $position,
            'is_sponsored' => $sponsored,
            'created_at' => now(),
        ]);

        if ($sponsored && Schema::hasTable('search_campaigns')) {
            DB::table('search_campaigns')
                ->where('app_id', $appId)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('status', 'active')
                ->increment('clicks');
        }

        return $id;
    }

    public function markConversion(int $appId, ?int $userId, string $type, int $targetId): bool
    {
        if (! Schema::hasTable('search_clicks')) {
            return false;
        }

        $click = DB::table('search_clicks')
            ->where('app_id', $appId)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->where('target_id', $targetId)
            ->whereNull('conversion_type')
            ->where('created_at', '>=', now()->subHours(24))
            ->latest('id')
            ->first();

        if (! $click) {
            return false;
        }

        DB::table('search_clicks')->where('id', $click->id)->update([
            'conversion_type' => mb_substr($type, 0, 40),
            'converted_at' => now(),
        ]);

        return true;
    }

    public function rememberEntity(int $appId, int $userId, array $item): void
    {
        if (! Schema::hasTable('search_recents')) {
            return;
        }

        DB::table('search_recents')->updateOrInsert(
            [
                'app_id' => $appId,
                'user_id' => $userId,
                'target_type' => (string) ($item['type'] ?? 'unknown'),
                'target_id' => (int) ($item['id'] ?? 0),
            ],
            [
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'subtitle' => mb_substr((string) ($item['subtitle'] ?? ''), 0, 500) ?: null,
                'image' => ! empty($item['image']) ? mb_substr((string) $item['image'], 0, 2048) : null,
                'url' => mb_substr((string) ($item['url'] ?? '/search'), 0, 2048),
                'searched_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function recent(int $appId, int $userId, int $limit = 20): Collection
    {
        if (! Schema::hasTable('search_recents')) {
            return collect();
        }

        return DB::table('search_recents')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->orderByDesc('searched_at')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn ($row) => [
                'type' => $row->target_type,
                'id' => (int) $row->target_id,
                'title' => $row->title,
                'subtitle' => $row->subtitle,
                'image' => $row->image,
                'url' => $row->url,
                'searched_at' => $row->searched_at,
            ]);
    }

    public function clearRecent(int $appId, int $userId, ?string $type = null, ?int $targetId = null): int
    {
        if (! Schema::hasTable('search_recents')) {
            return 0;
        }

        return DB::table('search_recents')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->when($type, fn ($query) => $query->where('target_type', $type))
            ->when($targetId, fn ($query) => $query->where('target_id', $targetId))
            ->delete();
    }

    public function saveQuery(int $appId, int $userId, array $parsed, ?string $label, bool $notify): array
    {
        if (! Schema::hasTable('search_saved_queries')) {
            return [];
        }

        $normalized = (string) ($parsed['normalized'] ?? '');
        DB::table('search_saved_queries')->updateOrInsert(
            [
                'app_id' => $appId,
                'user_id' => $userId,
                'normalized_query' => $normalized,
            ],
            [
                'label' => $label ? mb_substr(trim($label), 0, 120) : null,
                'query' => mb_substr((string) ($parsed['raw'] ?? ''), 0, 160),
                'filters' => json_encode($parsed['filters'] ?? [], JSON_UNESCAPED_UNICODE),
                'notifications_enabled' => $notify,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $row = DB::table('search_saved_queries')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('normalized_query', $normalized)
            ->first();

        return $row ? $this->savedRow($row) : [];
    }

    public function savedQueries(int $appId, int $userId): Collection
    {
        if (! Schema::hasTable('search_saved_queries')) {
            return collect();
        }

        return DB::table('search_saved_queries')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn ($row) => $this->savedRow($row));
    }

    public function deleteSavedQuery(int $appId, int $userId, int $id): bool
    {
        if (! Schema::hasTable('search_saved_queries')) {
            return false;
        }

        return (bool) DB::table('search_saved_queries')
            ->where('id', $id)
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->delete();
    }

    public function adminOverview(int $appId, int $days = 30): array
    {
        if (! Schema::hasTable('search_queries')) {
            return $this->emptyOverview();
        }

        $from = now()->subDays(max(1, min(365, $days)));
        $base = DB::table('search_queries')->where('app_id', $appId)->where('created_at', '>=', $from);
        $clicks = Schema::hasTable('search_clicks')
            ? DB::table('search_clicks')->where('app_id', $appId)->where('created_at', '>=', $from)
            : null;

        $totalSearches = (clone $base)->count();
        $zeroSearches = (clone $base)->where('zero_result', true)->count();
        $uniqueUsers = (clone $base)->whereNotNull('user_id')->distinct()->count('user_id');
        $clickCount = $clicks ? (clone $clicks)->count() : 0;
        $conversions = $clicks ? (clone $clicks)->whereNotNull('conversion_type')->count() : 0;

        $topTerms = (clone $base)
            ->selectRaw('normalized_query, MAX(query) as query, COUNT(*) as searches, SUM(zero_result) as zero_results')
            ->groupBy('normalized_query')
            ->orderByDesc('searches')
            ->limit(30)
            ->get();

        $zeroTerms = (clone $base)
            ->where('zero_result', true)
            ->selectRaw('normalized_query, MAX(query) as query, COUNT(*) as searches')
            ->groupBy('normalized_query')
            ->orderByDesc('searches')
            ->limit(30)
            ->get();

        $byCity = (clone $base)
            ->whereNotNull('city')
            ->selectRaw('city, uf, COUNT(*) as searches')
            ->groupBy('city', 'uf')
            ->orderByDesc('searches')
            ->limit(30)
            ->get();

        $byHour = (clone $base)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as searches')
            ->groupByRaw('HOUR(created_at)')
            ->orderBy('hour')
            ->get();

        $byDay = (clone $base)
            ->selectRaw('DAYOFWEEK(created_at) as weekday, COUNT(*) as searches')
            ->groupByRaw('DAYOFWEEK(created_at)')
            ->orderBy('weekday')
            ->get();

        $topTargets = $clicks
            ? (clone $clicks)
                ->selectRaw('target_type, target_id, COUNT(*) as clicks, SUM(is_sponsored) as sponsored_clicks, SUM(conversion_type IS NOT NULL) as conversions')
                ->groupBy('target_type', 'target_id')
                ->orderByDesc('clicks')
                ->limit(50)
                ->get()
            : collect();

        return [
            'period_days' => $days,
            'total_searches' => $totalSearches,
            'zero_result_searches' => $zeroSearches,
            'zero_result_rate' => $totalSearches > 0 ? round(($zeroSearches / $totalSearches) * 100, 2) : 0,
            'unique_users' => $uniqueUsers,
            'clicks' => $clickCount,
            'ctr' => $totalSearches > 0 ? round(($clickCount / $totalSearches) * 100, 2) : 0,
            'conversions' => $conversions,
            'conversion_rate' => $clickCount > 0 ? round(($conversions / $clickCount) * 100, 2) : 0,
            'top_terms' => $topTerms,
            'zero_terms' => $zeroTerms,
            'cities' => $byCity,
            'hours' => $byHour,
            'weekdays' => $byDay,
            'top_targets' => $topTargets,
        ];
    }

    public function producerDemand(int $appId, int $productionId, int $days = 30): array
    {
        if (! Schema::hasTable('search_queries')) {
            return ['terms' => [], 'cities' => [], 'opportunities' => []];
        }

        $from = now()->subDays(max(1, min(180, $days)));
        $base = DB::table('search_queries')
            ->where('app_id', $appId)
            ->where('created_at', '>=', $from);

        $terms = (clone $base)
            ->selectRaw('normalized_query, MAX(query) as query, COUNT(*) as searches, SUM(zero_result) as zero_results')
            ->groupBy('normalized_query')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('searches')
            ->limit(20)
            ->get();

        $cities = (clone $base)
            ->whereNotNull('city')
            ->selectRaw('city, uf, COUNT(*) as searches')
            ->groupBy('city', 'uf')
            ->orderByDesc('searches')
            ->limit(15)
            ->get();

        $opportunities = $terms
            ->filter(fn ($term) => (int) $term->zero_results > 0 || (int) $term->searches >= 5)
            ->take(10)
            ->map(fn ($term) => [
                'query' => $term->query,
                'searches' => (int) $term->searches,
                'zero_results' => (int) $term->zero_results,
                'message' => (int) $term->zero_results > 0
                    ? "Há procura por {$term->query} com resultados insuficientes."
                    : "A procura por {$term->query} está acima do normal.",
            ])
            ->values();

        return [
            'production_id' => $productionId,
            'period_days' => $days,
            'terms' => $terms,
            'cities' => $cities,
            'opportunities' => $opportunities,
        ];
    }

    private function savedRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'label' => $row->label,
            'query' => $row->query,
            'filters' => json_decode((string) ($row->filters ?? '{}'), true) ?: [],
            'notifications_enabled' => (bool) $row->notifications_enabled,
            'updated_at' => $row->updated_at,
        ];
    }

    private function emptyOverview(): array
    {
        return [
            'period_days' => 0,
            'total_searches' => 0,
            'zero_result_searches' => 0,
            'zero_result_rate' => 0,
            'unique_users' => 0,
            'clicks' => 0,
            'ctr' => 0,
            'conversions' => 0,
            'conversion_rate' => 0,
            'top_terms' => [],
            'zero_terms' => [],
            'cities' => [],
            'hours' => [],
            'weekdays' => [],
            'top_targets' => [],
        ];
    }
}
