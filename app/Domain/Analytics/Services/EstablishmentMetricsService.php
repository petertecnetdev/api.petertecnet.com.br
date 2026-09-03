<?php

namespace App\Domain\Analytics\Services;

use App\Models\Establishment;
use App\Models\Interaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

final class EstablishmentMetricsService
{
    public function for(Establishment $establishment): array
    {
        if (! $establishment->exists || ! $establishment->getKey()) {
            return $this->emptyMetrics();
        }

        return Cache::remember("establishment_{$establishment->getKey()}_metrics", 120, function () use ($establishment) {
            $views = $establishment->views();
            $items = $establishment->items();
            $employers = $establishment->employers();
            $orders = $establishment->orders();

            $totalViews = $views->count();
            $uniqueUsers = (clone $views)->whereNotNull('user_id')->distinct()->count('user_id');
            $totalItems = $items->count();
            $totalEmployers = $employers->count();
            $totalOrders = $orders->count();

            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended', 'completed'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();
            $totalRevenue = (float) (clone $orders)->sum('total_price');

            $clientsCount = (clone $orders)
                ->whereNotNull('client_id')
                ->selectRaw('client_id, COUNT(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');
            $returnRate = $clientsCount->isEmpty()
                ? 0
                : round(($clientsCount->filter(fn ($count) => $count > 1)->count() / $clientsCount->count()) * 100, 2);

            $totalEmployerViews = $totalEmployers === 0
                ? 0
                : Interaction::query()
                    ->where('entity_type', 'Employer')
                    ->whereIn('entity_id', $employers->pluck('id'))
                    ->where('interaction_type', 'view')
                    ->count();

            $firstView = $views->min('created_at');
            $firstView = $firstView && ! $firstView instanceof Carbon ? Carbon::parse($firstView) : $firstView;
            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;

            return [
                'total_items' => $totalItems,
                'total_employers' => $totalEmployers,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => round($totalRevenue, 2),
                'average_ticket' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
                'avg_revenue_per_employer' => $totalEmployers > 0 ? round($totalRevenue / $totalEmployers, 2) : 0,
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_user' => $uniqueUsers > 0 ? round($totalViews / $uniqueUsers, 2) : 0,
                'avg_views_per_item' => $totalItems > 0 ? round($totalViews / $totalItems, 2) : 0,
                'avg_views_per_employer' => $totalEmployers > 0 ? round($totalViews / $totalEmployers, 2) : 0,
                'avg_employer_views' => $totalEmployers > 0 ? round($totalEmployerViews / $totalEmployers, 2) : 0,
                'avg_views_per_day' => round($totalViews / max($daysActive, 1), 2),
                'days_active' => $daysActive,
                'engagement_score' => round(($uniqueUsers * 1.5) + ($totalViews * 0.2) + ($completedOrders * 1.2) + ($returnRate * 0.5), 2),
                'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0,
                'cancellation_rate' => $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0,
                'pending_rate' => $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0,
                'efficiency_rate' => ($completedOrders + $cancelledOrders) > 0
                    ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                    : 0,
                'return_rate' => $returnRate,
            ];
        });
    }

    private function emptyMetrics(): array
    {
        return [
            'total_items' => 0,
            'total_employers' => 0,
            'total_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
            'pending_orders' => 0,
            'total_revenue' => 0,
            'average_ticket' => 0,
            'avg_revenue_per_employer' => 0,
            'total_views' => 0,
            'unique_users' => 0,
            'avg_views_per_user' => 0,
            'avg_views_per_item' => 0,
            'avg_views_per_employer' => 0,
            'avg_employer_views' => 0,
            'avg_views_per_day' => 0,
            'days_active' => 0,
            'engagement_score' => 0,
            'completion_rate' => 0,
            'cancellation_rate' => 0,
            'pending_rate' => 0,
            'efficiency_rate' => 0,
            'return_rate' => 0,
        ];
    }
}
