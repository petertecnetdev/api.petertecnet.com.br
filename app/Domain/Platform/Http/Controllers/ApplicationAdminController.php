<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminOverviewService;
use App\Domain\Platform\Services\ApplicationAdminService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAdminController extends Controller
{
    public function __construct(
        private readonly ApplicationAdminService $service,
        private readonly ApplicationAdminOverviewService $overviewService,
    ) {
    }

    public function context(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->context($request->user(), $this->applicationId($request)),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->overviewService->overview($this->applicationId($request)),
        ]);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->permissionCatalog(),
        ]);
    }

    public function profiles(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->profiles($this->applicationId($request), $request->user()),
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'max:120'],
        ]);

        $profile = $this->service->createProfile(
            $this->applicationId($request),
            $request->user(),
            $validated,
            $this->auditContext($request),
        );

        return response()->json(['success' => true, 'data' => $profile], 201);
    }

    public function updateProfile(Request $request, int $profileId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'required', 'array'],
            'permissions.*' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->updateProfile(
                $this->applicationId($request),
                $profileId,
                $request->user(),
                $validated,
                $this->auditContext($request),
            ),
        ]);
    }

    public function destroyProfile(Request $request, int $profileId): JsonResponse
    {
        $this->service->deleteProfile(
            $this->applicationId($request),
            $profileId,
            $request->user(),
            $this->auditContext($request),
        );

        return response()->json(['success' => true]);
    }

    public function assignments(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->assignments($this->applicationId($request), $request->user()),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'profile_id' => ['required', 'integer', 'min:1'],
        ]);

        $assignment = $this->service->assign(
            $this->applicationId($request),
            $request->user(),
            $validated['email'],
            (int) $validated['profile_id'],
            $this->auditContext($request),
        );

        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    public function revoke(Request $request, int $assignmentId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->revoke(
                $this->applicationId($request),
                $assignmentId,
                $request->user(),
                $this->auditContext($request),
            ),
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->audit(
                $this->applicationId($request),
                $request->user(),
                (int) $request->integer('per_page', 50),
            ),
        ]);
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }

    private function auditContext(Request $request): array
    {
        return [
            'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }
}
