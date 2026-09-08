<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Domain\Platform\Services\ApplicationAdminOverviewService;
use App\Domain\Platform\Services\ApplicationAdminSecurityService;
use App\Domain\Platform\Services\ApplicationAdminService;
use App\Domain\Platform\Services\ApplicationAdminUserService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAdminController extends Controller
{
    public function __construct(
        private readonly ApplicationAdminService $service,
        private readonly ApplicationAdminOverviewService $overviewService,
        private readonly ApplicationAdminUserService $userService,
        private readonly ApplicationAdminSecurityService $securityService,
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

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->userService->paginate(
                $this->applicationId($request),
                $validated['q'] ?? null,
                (int) ($validated['per_page'] ?? 25),
            ),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['nullable', 'string', 'in:participant,producer,production_manager,artist,promoter,ticket_manager'],
        ]);

        $result = $this->userService->createOrAttach($this->applicationId($request), $validated);
        $target = $result['user'] instanceof User ? $result['user'] : User::query()->find($result['user']['id'] ?? null);

        $this->service->auditAction(
            $this->applicationId($request),
            $request->user(),
            $target,
            $result['created'] ? 'application_user_created' : 'application_user_attached',
            ['role' => $validated['role'] ?? 'participant'],
            $this->auditContext($request),
        );

        return response()->json([
            'success' => true,
            'message' => $result['created']
                ? 'Usuário criado e vinculado à aplicação com sucesso.'
                : 'Usuário existente vinculado à aplicação com sucesso.',
            'data' => $result['user'],
        ], $result['created'] ? 201 : 200);
    }

    public function userSecurity(Request $request, int $userId): JsonResponse
    {
        $target = User::query()->findOrFail($userId);

        return response()->json([
            'success' => true,
            'data' => $this->securityService->snapshot(
                $this->applicationId($request),
                $request->user(),
                $target,
            ),
        ]);
    }

    public function updateUserAccess(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,blocked'],
        ]);
        $target = User::query()->findOrFail($userId);

        return response()->json([
            'success' => true,
            'data' => $this->securityService->updateMembershipStatus(
                $this->applicationId($request),
                $request->user(),
                $target,
                $validated['status'],
                $this->auditContext($request),
            ),
        ]);
    }

    public function revokeUserSessions(Request $request, int $userId): JsonResponse
    {
        $target = User::query()->findOrFail($userId);

        return response()->json([
            'success' => true,
            'data' => $this->securityService->revokeSessions(
                $this->applicationId($request),
                $request->user(),
                $target,
                $this->auditContext($request),
            ),
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
            'authority' => $request->attributes->get('admin_authority'),
            'scope' => $request->attributes->get('admin_scope'),
        ];
    }
}
