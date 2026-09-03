<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Models\WebVitalSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebVitalController extends Controller
{
    private const METRICS = ['LCP', 'INP', 'CLS', 'FCP', 'TTFB'];

    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:80'],
            'metric_name' => ['required', 'string', 'in:' . implode(',', self::METRICS)],
            'metric_value' => ['required', 'numeric', 'min:0', 'max:600000'],
            'rating' => ['nullable', 'string', 'in:good,needs-improvement,poor'],
            'path' => ['nullable', 'string', 'max:500'],
            'device_class' => ['nullable', 'string', 'in:mobile,tablet,desktop,unknown'],
            'connection_type' => ['nullable', 'string', 'max:24'],
            'navigation_type' => ['nullable', 'string', 'max:32'],
        ]);

        $application = isset($data['application'])
            ? $this->discovery->resolveApplication($data['application'])
            : null;

        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }

        WebVitalSample::create([
            'application_id' => $application?->id,
            'session_id' => $data['session_id'] ?? null,
            'metric_name' => $data['metric_name'],
            'metric_value' => (float) $data['metric_value'],
            'rating' => $data['rating'] ?? null,
            'path' => $data['path'] ?? null,
            'device_class' => $data['device_class'] ?? null,
            'connection_type' => $data['connection_type'] ?? null,
            'navigation_type' => $data['navigation_type'] ?? null,
            'occurred_at' => now(),
        ]);

        return response()->json(['success' => true], 202);
    }
}
