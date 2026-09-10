<?php

namespace App\Http\Controllers;

use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    public function start(Request $request, $user): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json(
            $this->service->startById(
                $request->user(),
                (int) $user,
                (int) $data['application_id'],
                $data['reason'],
                $request
            ),
            201
        );
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handoff' => ['required', 'string', 'min:40', 'max:255'],
            'application_slug' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(
            $this->service->exchange($data['handoff'], $data['application_slug'] ?? null)
        );
    }

    public function current(): JsonResponse
    {
        return response()->json([
            'impersonation' => $this->service->currentPayload(),
        ]);
    }

    public function endCurrent(): JsonResponse
    {
        $ended = $this->service->endCurrent();

        return response()->json([
            'message' => $ended
                ? 'Acesso como usuário encerrado.'
                : 'Nenhuma sessão de impersonação ativa.',
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'status' => ['nullable', Rule::in(['active', 'ended', 'expired'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return response()->json($this->service->history($data));
    }

    public function audit(Request $request, $session): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);

        return response()->json($this->service->audit((int) $session, $data));
    }

    public function forceEnd(Request $request, $session): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(
            $this->service->forceEnd((int) $session, $request->user())
        );
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        $allowed = config('impersonation.super_admin_emails', []);

        abort_unless(
            $email !== '' && in_array($email, $allowed, true),
            403,
            'Somente o Super Admin autorizado pode assumir usuários.'
        );
    }
}
