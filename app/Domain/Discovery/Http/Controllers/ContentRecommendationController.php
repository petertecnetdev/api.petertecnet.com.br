<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Domain\Discovery\Services\ContentRecommendationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentRecommendationController extends Controller
{
    public function __construct(
        private readonly ContentRecommendationService $recommendations,
    ) {
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->recommendations->forContent(
                $slug,
                $data['application'] ?? null,
            ),
        ]);
    }
}
