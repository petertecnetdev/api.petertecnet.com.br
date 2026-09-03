<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\DiscoverySearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverySearchController extends Controller
{
    public function __construct(private readonly DiscoverySearchService $search)
    {
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'application' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->search->search(
                $data['q'],
                $data['city'] ?? null,
                $data['application'] ?? null,
                (int) ($data['limit'] ?? 8),
            ),
        ]);
    }

    public function landing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->search->landing($data['q'], $data['city'] ?? null, $data['application'] ?? null),
        ]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->search->landingCandidates((int) ($data['limit'] ?? 120)),
        ]);
    }
}
