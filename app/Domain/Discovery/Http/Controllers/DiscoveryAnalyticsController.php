<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContentEntry;
use App\Models\DiscoveryEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscoveryAnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $from = now()->subDays($days)->startOfDay();
        $query = DiscoveryEvent::query()->where('occurred_at', '>=', $from);

        if (! empty($data['application_id'])) {
            $applicationId = (int) $data['application_id'];
            if (! $actor->hasProfile('Administrador')) {
                abort_unless($actor->applications()->whereKey($applicationId)->exists(), 403);
            }
            $query->where('application_id', $applicationId);
        } elseif (! $actor->hasProfile('Administrador')) {
            $ids = $actor->applications()->pluck('applications.id');
            $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhereIn('application_id', $ids));
        }

        $byType = (clone $query)
            ->selectRaw('event_type, COUNT(*) total, COUNT(DISTINCT session_id) sessions')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->get();
        $totals = $byType->pluck('total', 'event_type');
        $sessions = (clone $query)->whereNotNull('session_id')->distinct()->count('session_id');
        $pageViews = (int) ($totals['page_view'] ?? 0);
        $cta = (int) ($totals['cta_click'] ?? 0);
        $conversions = (int) ($totals['conversion'] ?? 0);

        $sources = (clone $query)
            ->selectRaw("COALESCE(NULLIF(source, ''), 'direto') source, COUNT(*) total, COUNT(DISTINCT session_id) sessions")
            ->groupBy(DB::raw("COALESCE(NULLIF(source, ''), 'direto')"))
            ->orderByDesc('total')
            ->limit(12)
            ->get();

        $topEntities = (clone $query)
            ->whereNotNull('entity_type')
            ->whereNotNull('entity_id')
            ->selectRaw('entity_type, entity_id, COUNT(*) total, COUNT(DISTINCT session_id) sessions')
            ->groupBy('entity_type', 'entity_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $series = (clone $query)
            ->selectRaw('DATE(occurred_at) day, COUNT(*) total, COUNT(DISTINCT session_id) sessions')
            ->groupBy(DB::raw('DATE(occurred_at)'))
            ->orderBy('day')
            ->get();

        $contentPerformance = $this->contentPerformance($query);

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'summary' => [
                    'events' => (clone $query)->count(),
                    'sessions' => $sessions,
                    'page_views' => $pageViews,
                    'content_views' => (int) ($totals['content_view'] ?? 0),
                    'product_views' => (int) ($totals['product_view'] ?? 0),
                    'establishment_views' => (int) ($totals['establishment_view'] ?? 0),
                    'cta_clicks' => $cta,
                    'conversions' => $conversions,
                    'cta_rate' => $pageViews > 0 ? round(($cta / $pageViews) * 100, 2) : 0,
                    'conversion_rate' => $sessions > 0 ? round(($conversions / $sessions) * 100, 2) : 0,
                ],
                'funnel' => [
                    ['stage' => 'page_view', 'total' => $pageViews],
                    ['stage' => 'content_or_product_view', 'total' => (int) ($totals['content_view'] ?? 0) + (int) ($totals['product_view'] ?? 0) + (int) ($totals['establishment_view'] ?? 0)],
                    ['stage' => 'cta_click', 'total' => $cta],
                    ['stage' => 'conversion', 'total' => $conversions],
                ],
                'events_by_type' => $byType,
                'sources' => $sources,
                'top_entities' => $topEntities,
                'content_performance' => $contentPerformance,
                'series' => $series,
            ],
        ]);
    }

    private function contentPerformance($query)
    {
        // Aggregate by path in SQL and normalize the article slug in PHP. This keeps
        // the analytics portable across MySQL (production) and SQLite (test suite).
        $pathRows = (clone $query)
            ->where('path', 'like', '/blog/%')
            ->select('path')
            ->selectRaw('COUNT(*) events')
            ->selectRaw("SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) page_views")
            ->selectRaw("SUM(CASE WHEN event_type = 'content_view' THEN 1 ELSE 0 END) content_views")
            ->selectRaw("SUM(CASE WHEN event_type = 'cta_click' THEN 1 ELSE 0 END) cta_clicks")
            ->selectRaw("SUM(CASE WHEN event_type = 'outbound_click' THEN 1 ELSE 0 END) outbound_clicks")
            ->groupBy('path')
            ->get();

        if ($pathRows->isEmpty()) {
            return collect();
        }

        $rows = $pathRows->reduce(function ($carry, $row) {
            $slug = $this->blogSlug($row->path);
            if (! $slug) {
                return $carry;
            }

            $current = $carry->get($slug, [
                'slug' => $slug,
                'events' => 0,
                'page_views' => 0,
                'content_views' => 0,
                'cta_clicks' => 0,
                'outbound_clicks' => 0,
            ]);

            foreach (['events', 'page_views', 'content_views', 'cta_clicks', 'outbound_clicks'] as $metric) {
                $current[$metric] += (int) $row->{$metric};
            }

            $carry->put($slug, $current);
            return $carry;
        }, collect());

        if ($rows->isEmpty()) {
            return collect();
        }

        $slugs = $rows->keys()->values();
        $content = ContentEntry::query()
            ->whereIn('slug', $slugs)
            ->get(['slug', 'title', 'category', 'status', 'published_at'])
            ->keyBy('slug');

        $convertedSessions = (clone $query)
            ->where('event_type', 'conversion')
            ->whereNotNull('session_id')
            ->distinct()
            ->pluck('session_id')
            ->flip();

        $blogSessions = (clone $query)
            ->where('path', 'like', '/blog/%')
            ->whereNotNull('session_id')
            ->select(['path', 'session_id'])
            ->distinct()
            ->get()
            ->map(function ($row) {
                return [
                    'slug' => $this->blogSlug($row->path),
                    'session_id' => $row->session_id,
                ];
            })
            ->filter(fn (array $row) => ! empty($row['slug']) && ! empty($row['session_id']))
            ->groupBy('slug');

        return $rows
            ->map(function (array $row, string $slug) use ($content, $blogSessions, $convertedSessions) {
                $entry = $content->get($slug);
                $sessionIds = ($blogSessions->get($slug) ?? collect())->pluck('session_id')->unique();
                $sessions = $sessionIds->count();
                $contentViews = (int) $row['content_views'];
                $pageViews = (int) $row['page_views'];
                $views = max($contentViews, $pageViews);
                $ctaClicks = (int) $row['cta_clicks'];
                $outboundClicks = (int) $row['outbound_clicks'];
                $assistedConversions = $sessionIds
                    ->filter(fn ($sessionId) => $convertedSessions->has($sessionId))
                    ->count();

                $ctaRate = $sessions > 0 ? round(($ctaClicks / $sessions) * 100, 2) : 0;
                $conversionRate = $sessions > 0 ? round(($assistedConversions / $sessions) * 100, 2) : 0;
                $score = $sessions + ($views * 2) + ($ctaClicks * 6) + ($outboundClicks * 3) + ($assistedConversions * 15);

                return [
                    'slug' => $slug,
                    'title' => $entry?->title ?: str_replace('-', ' ', $slug),
                    'category' => $entry?->category,
                    'status' => $entry?->status,
                    'published_at' => $entry?->published_at,
                    'events' => (int) $row['events'],
                    'sessions' => $sessions,
                    'views' => $views,
                    'cta_clicks' => $ctaClicks,
                    'outbound_clicks' => $outboundClicks,
                    'assisted_conversions' => $assistedConversions,
                    'cta_rate' => $ctaRate,
                    'conversion_rate' => $conversionRate,
                    'score' => $score,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->take(50)
            ->map(function (array $row, int $index) {
                $rank = $index + 1;
                return [
                    ...$row,
                    'rank' => $rank,
                    'instagram_recommended' => $rank <= 3 && $row['views'] > 0,
                ];
            });
    }

    private function blogSlug(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $pathOnly = parse_url($path, PHP_URL_PATH) ?: $path;
        if (! preg_match('#^/blog/([^/?#]+)#', $pathOnly, $matches)) {
            return null;
        }

        $slug = trim(rawurldecode($matches[1]));
        return $slug !== '' ? $slug : null;
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('marketing_dashboard')), 403);
        return $actor;
    }
}
