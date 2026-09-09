<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ImpersonationAuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $service)
    {
    }

    public function start(Request $request, User $user): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $application = Application::query()->findOrFail((int) $data['application_id']);
        $result = $this->service->start($request->user(), $user, $application, $data['reason'], $request);

        return response()->json([
            'message' => 'Acesso temporário criado com segurança.',
            'impersonation' => $this->service->payload($result['session']),
            'handoff_url' => $result['handoff_url'],
            'handoff_expires_at' => $result['handoff_expires_at']?->toIso8601String(),
        ], 201);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handoff' => ['required', 'string', 'min:40', 'max:255'],
            'application_slug' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->service->exchange($data['handoff'], $data['application_slug'] ?? null));
    }

    public function current(Request $request): JsonResponse
    {
        $session = $this->service->currentFromToken();
        if (! $session) {
            return response()->json(['impersonation' => null]);
        }

        return response()->json(['impersonation' => $this->service->payload($session)]);
    }

    public function endCurrent(Request $request): JsonResponse
    {
        $session = $this->service->currentFromToken();
        if (! $session) {
            return response()->json(['message' => 'Nenhuma sessão de impersonação ativa.']);
        }

        $session->finish($session->impersonator, 'ended_from_application');

        try {
            auth('api')->logout();
        } catch (\Throwable) {
            // The database state is authoritative even if JWT blacklisting is unavailable.
        }

        return response()->json(['message' => 'Acesso como usuário encerrado.']);
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

        $query = ImpersonationSession::query()
            ->with(['impersonator:id,first_name,last_name,user_name,email', 'impersonatedUser:id,first_name,last_name,user_name,email', 'application:id,name,slug,url'])
            ->withCount('auditLogs')
            ->latest('id');

        if (! empty($data['user_id'])) {
            $query->where(function ($q) use ($data) {
                $q->where('impersonated_user_id', $data['user_id'])
                    ->orWhere('impersonator_user_id', $data['user_id']);
            });
        }
        if (! empty($data['application_id'])) $query->where('application_id', $data['application_id']);

        if (($data['status'] ?? null) === 'active') {
            $query->whereNull('ended_at')->where('expires_at', '>', now());
        } elseif (($data['status'] ?? null) === 'ended') {
            $query->whereNotNull('ended_at');
        } elseif (($data['status'] ?? null) === 'expired') {
            $query->whereNull('ended_at')->where('expires_at', '<=', now());
        }

        $perPage = (int) ($data['per_page'] ?? 25);
        $page = $query->paginate($perPage);

        return response()->json([
            'sessions' => collect($page->items())->map(fn (ImpersonationSession $session) => array_merge(
                $this->service->payload($session),
                [
                    'end_reason' => $session->end_reason,
                    'audit_logs_count' => (int) $session->audit_logs_count,
                    'ip_address' => $session->ip_address,
                    'created_at' => $session->created_at?->toIso8601String(),
                ]
            ))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function audit(Request $request, ImpersonationSession $session): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);

        $page = ImpersonationAuditLog::query()
            ->where('impersonation_session_id', $session->id)
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'impersonation' => $this->service->payload($session),
            'audit' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function forceEnd(Request $request, ImpersonationSession $session): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $session->finish($request->user(), 'ended_from_admincenter');

        return response()->json([
            'message' => 'Sessão de impersonação encerrada.',
            'impersonation' => $this->service->payload($session->fresh()),
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        $email = strtolower(trim((string) $request->user()?->email));
        $allowed = config('impersonation.super_admin_emails', []);

        abort_unless($email !== '' && in_array($email, $allowed, true), 403, 'Somente o Super Admin autorizado pode assumir usuários.');
    }
}
