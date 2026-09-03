<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
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
                'series' => $series,
            ],
        ]);
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('marketing_dashboard')), 403);
        return $actor;
    }
}
