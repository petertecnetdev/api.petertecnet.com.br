<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentitySessionController extends Controller
{
    public function __construct(
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $current = $this->sessions->current();
        $sessions = $this->sessions->activeFor($request->user('api'));

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn (IdentitySession $session) => array_merge($this->sessions->present($session), [
                'current' => $current && $current->session_id === $session->session_id,
            ]))->values(),
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $session = IdentitySession::query()->with(['application', 'device'])->where('session_id', $sessionId)
            ->where('user_id', $request->user('api')->id)->firstOrFail();

        $this->sessions->revoke($session, 'user_revoked');
        $this->audit->record('session_revoked', $request->user('api'), $request, $session->application, [
            'session_id' => $session->session_id,
            'device_id' => $session->device?->device_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Sessão do aplicativo encerrada.']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $current = $this->sessions->current();
        $appCount = $this->sessions->revokeAll($user, 'user_revoked_others', $current?->session_id);
        $globalQuery = IdentityGlobalSession::query()->where('user_id', $user->id)->whereNull('revoked_at');
        if ($current?->device_id) $globalQuery->where('device_id', '!=', $current->device_id);
        $globalCount = $globalQuery->update(['revoked_at' => now(), 'revoke_reason' => 'user_revoked_other_devices', 'updated_at' => now()]);

        $this->audit->record('other_sessions_revoked', $user, $request, $current?->application, [
            'application_sessions' => $appCount,
            'global_sessions' => $globalCount,
            'kept_current' => true,
        ], true);

        return response()->json([
            'success' => true,
            'message' => 'Outras sessões e continuidades SSO foram encerradas.',
            'revoked' => $appCount + $globalCount,
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $current = $this->sessions->current();
        $appCount = $this->sessions->revokeAll($user, 'user_revoked_all');
        $globalCount = $this->globalSessions->revokeAll($user, 'user_revoked_all');
        $user->forceFill(['auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1])->save();

        $this->audit->record('all_sessions_revoked', $user, $request, $current?->application, [
            'application_sessions' => $appCount,
            'global_sessions' => $globalCount,
            'kept_current' => false,
        ], true);

        try { auth('api')->logout(); } catch (\Throwable) {}
        $response = response()->json([
            'success' => true,
            'message' => 'Todas as sessões e tokens da conta foram revogados.',
            'revoked' => $appCount + $globalCount,
        ]);
        foreach ($this->globalSessions->forgetCookies() as $cookie) $response->withCookie($cookie);
        return $response;
    }
}
