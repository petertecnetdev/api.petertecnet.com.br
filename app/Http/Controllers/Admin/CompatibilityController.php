<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompatibilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);

        $days = (int) ($filters['days'] ?? 14);
        $from = now()->subDays($days);

        $base = Interaction::query()
            ->where('interaction_type', 'compatibility_route')
            ->where('created_at', '>=', $from)
            ->when(isset($filters['app_id']), fn ($query) => $query->where('app_id', (int) $filters['app_id']))
            ->when(! empty($filters['path']), fn ($query) => $query->where('route', 'like', '%'.$filters['path'].'%'));

        $summary = (clone $base)
            ->select([
                'app_id',
                'route',
                'method',
                DB::raw('COUNT(*) as requests'),
                DB::raw("SUM(CASE WHEN outcome = 'error' THEN 1 ELSE 0 END) as errors"),
                DB::raw('MIN(created_at) as first_used_at'),
                DB::raw('MAX(created_at) as last_used_at'),
            ])
            ->groupBy('app_id', 'route', 'method')
            ->orderByDesc('last_used_at')
            ->limit(500)
            ->get();

        $applications = DB::table('applications')
            ->whereIn('id', $summary->pluck('app_id')->filter()->unique()->values())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        return response()->json([
            'window_days' => $days,
            'total_requests' => (clone $base)->count(),
            'routes' => $summary->map(function ($row) use ($applications) {
                $application = $applications->get($row->app_id);

                return [
                    'application' => $application ? [
                        'id' => $application->id,
                        'name' => $application->name,
                        'slug' => $application->slug,
                    ] : null,
                    'route' => $row->route,
                    'method' => $row->method,
                    'requests' => (int) $row->requests,
                    'errors' => (int) $row->errors,
                    'first_used_at' => $row->first_used_at,
                    'last_used_at' => $row->last_used_at,
                    'ready_to_remove' => false,
                ];
            })->values(),
        ]);
    }
}
