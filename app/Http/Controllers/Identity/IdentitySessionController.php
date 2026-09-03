<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\IdentityStepUpService;
use App\Domain\Identity\Services\IdentityTrustedDeviceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentitySessionController extends Controller
{
    public function __construct(
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityTrustedDeviceService $trustedDevices,
        private readonly IdentityStepUpService $stepUp,
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

    public function rename(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate(['nickname' => ['required', 'string', 'max:120']]);
        $session = $this->sessions->rename($request->user('api'), $sessionId, $data['nickname']);
        $session->load('application');
        $this->audit->record('session_renamed', $request->user('api'), $request, $session->application, [
            'session_id' => $session->session_id,
            'nickname' => $session->nickname,
        ]);

        return response()->json(['success' => true, 'data' => $this->sessions->present($session)]);
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
        ], true);

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
        $user = $request->user('api');
        $current = $this->sessions->current();
        $appCount = $this->sessions->revokeAll($user, 'user_revoked_all');
        $globalCount = $this->globalSessions->revokeAll($user, 'user_revoked_all');
        $trustedCount = $this->trustedDevices->revokeAll($user, 'user_revoked_all');
        $this->stepUp->revokeAll($user);

        $user->forceFill([
            'auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1,
        ])->save();

        $this->audit->record('all_sessions_revoked', $user, $request, $current?->application, [
            'revoked_count' => $appCount,
            'global_sessions_revoked' => $globalCount,
            'trusted_devices_revoked' => $trustedCount,
            'kept_current' => false,
        ], true);

        try {
            auth('api')->logout();
        } catch (\Throwable) {
        }

        $response = response()->json([
            'success' => true,
            'message' => 'Todas as sessões e dispositivos confiáveis foram encerrados.',
            'revoked' => $appCount + $globalCount + $trustedCount,
        ])->withCookie($this->trustedDevices->forgetCookie());

        foreach ($this->globalSessions->forgetCookies() as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
