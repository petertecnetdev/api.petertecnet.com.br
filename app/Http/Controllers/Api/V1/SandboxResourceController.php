<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SandboxResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SandboxResourceController extends Controller
{
    public function index(Request $request, string $resourceType)
    {
        $project = $request->attributes->get('api_project');
        abort_unless($project && $project->environment === 'sandbox', 403);
        $items = SandboxResource::query()
            ->where('api_project_id', $project->id)
            ->where('resource_type', $resourceType)
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return ApiResponse::paginated($items);
    }

    public function store(Request $request, string $resourceType)
    {
        $project = $request->attributes->get('api_project');
        abort_unless($project && $project->environment === 'sandbox', 403);
        $data = $request->validate(['data' => ['required', 'array', 'max:200']]);
        $resource = SandboxResource::create([
            'api_project_id' => $project->id,
            'resource_type' => $resourceType,
            'payload' => $data['data'],
        ]);

        return ApiResponse::success($resource, [], 201);
    }

    public function show(Request $request, string $resourceType, string $publicId)
    {
        $project = $request->attributes->get('api_project');
        abort_unless($project && $project->environment === 'sandbox', 403);
        $resource = SandboxResource::query()
            ->where('api_project_id', $project->id)
            ->where('resource_type', $resourceType)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return ApiResponse::success($resource);
    }

    public function update(Request $request, string $resourceType, string $publicId)
    {
        $project = $request->attributes->get('api_project');
        abort_unless($project && $project->environment === 'sandbox', 403);
        $data = $request->validate(['data' => ['required', 'array', 'max:200']]);
        $resource = SandboxResource::query()
            ->where('api_project_id', $project->id)
            ->where('resource_type', $resourceType)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $resource->update(['payload' => $data['data']]);

        return ApiResponse::success($resource->fresh());
    }

    public function destroy(Request $request, string $resourceType, string $publicId)
    {
        $project = $request->attributes->get('api_project');
        abort_unless($project && $project->environment === 'sandbox', 403);
        $resource = SandboxResource::query()
            ->where('api_project_id', $project->id)
            ->where('resource_type', $resourceType)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $resource->delete();
        return ApiResponse::success(['deleted' => true]);
    }
}
