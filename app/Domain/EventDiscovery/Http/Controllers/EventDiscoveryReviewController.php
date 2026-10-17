<?php

namespace App\Domain\EventDiscovery\Http\Controllers;

use App\Domain\EventDiscovery\Services\EventDiscoveryReviewService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventDiscoveryReviewController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventDiscoveryReviewService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', 'in:pending,possible_duplicate,rejected,drafted'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(array_merge(
            ['success' => true],
            $this->service->index(
                $this->context->id(),
                $input['status'] ?? null,
                (int) ($input['per_page'] ?? 25),
            )
        ));
    }

    public function review(Request $request, int $candidateId): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:reject,create_draft'],
            'production_id' => ['required_if:decision,create_draft', 'nullable', 'integer', 'min:1'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->review(
            $this->context->id(),
            $this->context->slug(),
            (int) $request->user('api')->getKey(),
            $candidateId,
            $input,
        );

        return response()->json(['success' => true, 'data' => $result]);
    }
}
