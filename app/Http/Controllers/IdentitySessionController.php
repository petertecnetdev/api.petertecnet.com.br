<?php

namespace App\Http\Controllers;

use App\Models\IdentityAuthEvent;
use App\Models\IdentitySession;
use App\Services\IdentitySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IdentitySessionController extends Controller
{
    public function __construct(private readonly IdentitySessionService $identity)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->identity->resolve($request);

        $sessions = IdentitySession::query()
            ->with(['device', 'lastApplication'])
            ->where('user_id', $user->id)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (IdentitySession $session) => $this->payload(
                $session,
                $current && hash_equals((string) $current->id, (string) $session->id)
            ));

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $sessions,
                'current_session_id' => $current && (int) $current->user_id === (int) $user->id
                    ? $current->id
                    : null,
            ],
        ]);
    }

    public function destroy(Request $request, IdentitySession $identitySession): JsonResponse|Response
    {
        abort_unless((int) $identitySession->user_id === (int) $request->user()->id, 404);

        $current = $this->identity->resolve($request);
        $isCurrent = $current && hash_equals((string) $current->id, (string) $identitySession->id);
        $this->identity->revoke($identitySession, 'user_revoked_session', $request);

        $response = response()->noContent();
        if ($isCurrent) {
            $response->withCookies($this->identity->forgetCookies());
        }

        return $response;
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->identity->resolve($request);
        $except = $current && (int) $current->user_id === (int) $user->id ? $current->id : null;
        $count = $this->identity->revokeUserSessions($user, 'user_revoked_other_sessions', $except, $request);

        $this->identity->audit($request, 'other_sessions_revoked', 'success', $user, $current, null, [
            'revoked_count' => $count,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['revoked_count' => $count],
        ]);
    }

    public function logout(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:current-app,global'],
        ]);

        $scope = (string) ($data['scope'] ?? 'current-app');
        $user = $request->user();
        $current = $this->identity->resolve($request);

        if ($scope === 'global') {
            $user->increment('auth_version');
            $user->refresh();
            $count = $this->identity->revokeUserSessions($user, 'global_logout', null, $request);
            $this->identity->audit($request, 'global_logout', 'success', $user, $current, null, [
                'revoked_sessions' => $count,
                'auth_version' => (int) $user->auth_version,
            ]);

            try {
                auth('api')->logout();
            } catch (\Throwable) {
                // The auth_version bump is authoritative even if the JWT was already expired.
            }

            return response()->noContent()->withCookies($this->identity->forgetCookies());
        }

        $this->identity->audit($request, 'application_logout', 'success', $user, $current, null);
        try {
            auth('api')->logout();
        } catch (\Throwable) {
            // Local logout remains idempotent.
        }

        return response()->noContent();
    }

    public function events(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 50), 200));

        $events = IdentityAuthEvent::query()
            ->with('application:id,slug,name')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (IdentityAuthEvent $event) => [
                'id' => $event->id,
                'type' => $event->event_type,
                'outcome' => $event->outcome,
                'application' => $event->application?->only(['id', 'slug', 'name']),
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'metadata' => $event->metadata,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => ['events' => $events],
        ]);
    }

    private function payload(IdentitySession $session, bool $current): array
    {
        return [
            'id' => $session->id,
            'current' => $current,
            'active' => $session->active(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
            'revoke_reason' => $session->revoke_reason,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'refresh_expires_at' => $session->refresh_expires_at?->toIso8601String(),
            'last_application' => $session->lastApplication?->only(['id', 'slug', 'name']),
            'device' => $session->device ? [
                'id' => $session->device->uuid,
                'name' => $session->device->name,
                'platform' => $session->device->platform,
                'browser' => $session->device->browser,
                'trusted' => (bool) $session->device->trusted,
                'last_ip_address' => $session->device->last_ip_address,
                'first_seen_at' => $session->device->first_seen_at?->toIso8601String(),
                'last_seen_at' => $session->device->last_seen_at?->toIso8601String(),
            ] : null,
        ];
    }
}
