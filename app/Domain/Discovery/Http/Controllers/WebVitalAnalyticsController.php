<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebVitalSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WebVitalAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $applicationId = isset($data['application_id']) ? (int) $data['application_id'] : null;
        if ($applicationId && ! $actor->hasProfile('Administrador')) {
            abort_unless($actor->applications()->whereKey($applicationId)->exists(), 403);
        }

        $query = WebVitalSample::query()->where('occurred_at', '>=', now()->subDays($days)->startOfDay());
        if ($applicationId) {
            $query->where('application_id', $applicationId);
        } elseif (! $actor->hasProfile('Administrador')) {
            $ids = $actor->applications()->pluck('applications.id');
            $query->where(fn ($scope) => $scope->whereNull('application_id')->orWhereIn('application_id', $ids));
        }

        $samples = $query->latest('occurred_at')->limit(10000)->get();
        $metrics = $samples->groupBy('metric_name')->map(fn (Collection $group, string $metric) => $this->metricPayload($metric, $group))->values();
        $paths = $samples->groupBy(fn ($sample) => $sample->path ?: '/')
            ->map(function (Collection $group, string $path) {
                return [
                    'path' => $path,
                    'samples' => $group->count(),
                    'poor' => $group->where('rating', 'poor')->count(),
                    'metrics' => $group->groupBy('metric_name')->map(fn (Collection $metric, string $name) => [
                        'name' => $name,
                        'p75' => $this->percentile($metric->pluck('metric_value')->map(fn ($value) => (float) $value), .75),
                    ])->values(),
                ];
            })->sortByDesc('poor')->take(40)->values();

        $devices = $samples->groupBy(fn ($sample) => $sample->device_class ?: 'unknown')
            ->map(fn (Collection $group, string $device) => [
                'device' => $device,
                'samples' => $group->count(),
                'good_rate' => $group->count() ? round(($group->where('rating', 'good')->count() / $group->count()) * 100, 2) : 0,
                'poor_rate' => $group->count() ? round(($group->where('rating', 'poor')->count() / $group->count()) * 100, 2) : 0,
            ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'samples' => $samples->count(),
                'metrics' => $metrics,
                'paths' => $paths,
                'devices' => $devices,
            ],
        ]);
    }

    private function metricPayload(string $metric, Collection $group): array
    {
        return [
            'name' => $metric,
            'samples' => $group->count(),
            'p50' => $this->percentile($group->pluck('metric_value')->map(fn ($value) => (float) $value), .50),
            'p75' => $this->percentile($group->pluck('metric_value')->map(fn ($value) => (float) $value), .75),
            'p95' => $this->percentile($group->pluck('metric_value')->map(fn ($value) => (float) $value), .95),
            'good_rate' => $group->count() ? round(($group->where('rating', 'good')->count() / $group->count()) * 100, 2) : 0,
            'poor_rate' => $group->count() ? round(($group->where('rating', 'poor')->count() / $group->count()) * 100, 2) : 0,
        ];
    }

    private function percentile(Collection $values, float $percentile): float
    {
        $sorted = $values->sort()->values();
        if ($sorted->isEmpty()) return 0;
        $index = (int) ceil($percentile * $sorted->count()) - 1;
        return round((float) $sorted[max(0, min($index, $sorted->count() - 1))], 3);
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('marketing_dashboard')), 403);
        return $actor;
    }
}
