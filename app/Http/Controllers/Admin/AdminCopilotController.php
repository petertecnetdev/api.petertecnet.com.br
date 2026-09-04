<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminActionRegistry;
use App\Services\AdminCopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCopilotController extends Controller
{
    public function __construct(
        private readonly AdminActionRegistry $registry,
        private readonly AdminCopilotService $copilot,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        $this->authorizeCopilot($request);

        return response()->json([
            'version' => 1,
            'capabilities' => $this->registry->publicCapabilities(),
            'safety' => [
                'destructive_voice_actions' => false,
                'requires_confirmation' => ['confirm', 'strong-confirm'],
                'audit_source' => 'admin_copilot',
            ],
        ]);
    }

    public function preflight(Request $request): JsonResponse
    {
        $this->authorizeCopilot($request);
        $data = $request->validate([
            'actions' => ['required', 'array', 'min:1', 'max:25'],
            'actions.*.key' => ['required', 'string', 'max:100'],
            'actions.*.payload' => ['required', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        return response()->json($this->copilot->preflight(
            $data['actions'],
            $data['context'] ?? [],
        ));
    }

    public function audit(Request $request): JsonResponse
    {
        $this->authorizeCopilot($request);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:success,error,cancelled'],
            'transcript' => ['nullable', 'string', 'max:10000'],
            'plan' => ['required', 'array'],
            'result' => ['nullable', 'array'],
            'session_id' => ['nullable', 'string', 'max:120'],
        ]);

        $entry = $this->copilot->audit(
            $request->user(),
            $data,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['logged' => true, 'id' => $entry->id], 201);
    }

    private function authorizeCopilot(Request $request): void
    {
        $actor = $request->user();
        abort_unless(
            $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_create') || $actor->hasPermission('application_manage')),
            403,
            'Você não tem permissão para usar o Admin Copilot.'
        );
    }
}
