<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\PublicUserProfileService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicUserProfileIndexController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicUserProfileService $service,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'profiles' => $this->service->previewIndex(
                $this->context->id(),
                (int) ($data['per_page'] ?? 100),
            ),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}