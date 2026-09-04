<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ResourceRef;
use App\Services\ContextualAccessService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextualAccessController extends Controller
{
    public function __construct(
        private readonly ContextualAccessService $access,
        private readonly ApplicationContext $context,
    ) {
    }

    public function relationships(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'resource_uuid' => ['nullable', 'uuid'],
            'resource_type' => ['nullable', 'string', 'max:120'],
            'resource_id' => ['nullable', 'integer', 'min:1'],
            'relationship_type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->access->relationshipsPage(
            $request->user(),
            $this->context->id(),
            $filters,
        ));
    }

    public function authorizeResource(Request $request, string $resourceRef): JsonResponse
    {
        $data = $request->validate(['permission' => ['required', 'string', 'max:160']]);
        $resource = ResourceRef::query()->active()
            ->where('uuid', $resourceRef)
            ->where('application_id', $this->context->id())
            ->firstOrFail();

        return response()->json([
            'allowed' => $this->access->canForResource($request->user(), $data['permission'], $resource),
            'resource' => [
                'uuid' => $resource->uuid,
                'resource_type' => $resource->resource_type,
                'resource_id' => $resource->resource_id,
            ],
        ]);
    }
}
