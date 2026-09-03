<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentitySessionController extends Controller
{
    public function __construct(
        private readonly IdentitySessionService $sessions,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $current = $this->sessions->current();
        $sessions = $this->sessions->activeFor($request->user('api'));

        return response()->json([
            'success' => true,
            'data' => $sessions->map(function (IdentitySession $session) use ($current) {
                return array_merge($this->sessions->present($session), [
                    'current' => $current && $current->session_id === $session->session_id,
                ]);
            })->values(),
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $session = IdentitySession::query()
            ->where('session_id', $sessionId)
            ->where('user_id', $request->user('api')->id)
            ->firstOrFail();

        $this->sessions->revoke($session, 'user_revoked');
        $this->audit->record('session_revoked', $request->user('api'), $request, $session->application, [
            'session_id' => $session->session_id,
            'device' => $session->device_label,
        ]);

        return response()->json(['success' => true, 'message' => 'Sessão encerrada.']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $current = $this->sessions->current();
        $count = $this->sessions->revokeAll(
            $request->user('api'),
            'user_revoked_others',
            $current?->session_id
        );

        $this->audit->record('all_sessions_revoked', $request->user('api'), $request, $current?->application, [
            'revoked_count' => $count,
            'kept_current' => true,
        ], true);

        return response()->json([
            'success' => true,
            'message' => 'Outras sessões encerradas.',
            'revoked' => $count,
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $current = $this->sessions->current();
        $count = $this->sessions->revokeAll($request->user('api'), 'user_revoked_all');
        $this->audit->record('all_sessions_revoked', $request->user('api'), $request, $current?->application, [
            'revoked_count' => $count,
            'kept_current' => false,
        ], true);

        try {
            auth('api')->logout();
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'message' => 'Todas as sessões foram encerradas.',
            'revoked' => $count,
        ]);
    }
}
