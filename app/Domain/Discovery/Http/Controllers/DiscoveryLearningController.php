<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoveryLearningService;
use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use App\Domain\Discovery\Services\DiscoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DiscoveryLearningController extends Controller
{
    public function __construct(
        private readonly DiscoveryLearningService $learning,
        private readonly DiscoverySearchIndexService $searchIndex,
        private readonly DiscoveryService $discovery,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'application' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $application = isset($data['application'])
            ? $this->discovery->resolveApplication($data['application'])
            : null;
        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->searchIndex->search(
                $data['q'],
                $data['city'] ?? null,
                $application?->id,
                (int) ($data['limit'] ?? 8)
            ),
        ]);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:100'],
            'application' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $application = isset($data['application'])
            ? $this->discovery->resolveApplication($data['application'])
            : null;
        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->learning->recommendations(
                $data['session_id'] ?? null,
                (int) ($data['limit'] ?? 8),
                $application?->id
            ),
        ]);
    }

    public function experiment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'surface' => ['required', 'string', 'max:160'],
            'session_id' => ['required', 'string', 'max:100'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);
        $application = isset($data['application']) ? $this->discovery->resolveApplication($data['application']) : null;
        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->learning->resolveExperiment(
                $data['surface'],
                $data['session_id'],
                $application?->id
            ),
        ]);
    }

    public function experimentEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'experiment_id' => ['required', 'integer', 'exists:discovery_experiments,id'],
            'variant_key' => ['required', 'string', 'max:80'],
            'session_id' => ['required', 'string', 'max:100'],
            'event_type' => ['required', 'string', 'in:exposure,conversion'],
            'conversion_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'metadata' => ['nullable', 'array'],
        ]);
        $this->learning->experimentEvent(
            (int) $data['experiment_id'],
            $data['variant_key'],
            $data['session_id'],
            $data['event_type'],
            isset($data['conversion_value']) ? (float) $data['conversion_value'] : null,
            $data['metadata'] ?? []
        );

        return response()->json(['success' => true], 202);
    }

    public function accessibility(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['nullable', 'string', 'max:120'],
            'session_id' => ['nullable', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:1000'],
            'score' => ['required', 'integer', 'min:0', 'max:100'],
            'device_class' => ['nullable', 'string', 'max:32'],
            'issues' => ['nullable', 'array', 'max:60'],
            'issues.*.code' => ['required_with:issues', 'string', 'max:80'],
            'issues.*.count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ]);
        $application = isset($data['application']) ? $this->discovery->resolveApplication($data['application']) : null;
        if (isset($data['application']) && ! $application) {
            abort(422, 'Aplicação inválida.');
        }
        DB::table('experience_audits')->insert([
            'application_id' => $application?->id,
            'session_id' => $data['session_id'] ?? null,
            'path' => $data['path'],
            'score' => (int) $data['score'],
            'device_class' => $data['device_class'] ?? null,
            'issues' => isset($data['issues']) ? json_encode($data['issues']) : null,
            'metadata' => isset($data['metadata'])
                ? json_encode(Arr::only($data['metadata'], ['viewport', 'reduced_motion']))
                : null,
            'audited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true], 202);
    }
}
