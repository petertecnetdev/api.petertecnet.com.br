<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationAdminImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    public function start(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json(
            $this->service->startById(
                $request->user(),
                $userId,
                $this->applicationId($request),
                $data['reason'],
                $request,
                'application_admin'
            ),
            201
        );
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['active', 'ended', 'expired'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $data['application_id'] = $this->applicationId($request);

        return response()->json($this->service->history($data));
    }

    public function audit(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);

        return response()->json(
            $this->service->auditForApplication(
                $sessionId,
                $this->applicationId($request),
                $data
            )
        );
    }

    public function forceEnd(Request $request, int $sessionId): JsonResponse
    {
        return response()->json(
            $this->service->forceEndForApplication(
                $sessionId,
                $this->applicationId($request),
                $request->user()
            )
        );
    }

    private function applicationId(Request $request): int
    {
        return (int) $request->attributes->get('app_id');
    }
}
