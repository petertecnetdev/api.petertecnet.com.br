<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentitySession;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdentitySessionService
{
    public function __construct(
        private readonly IdentityDeviceService $devices,
        private readonly IdentityRiskService $risk,
        private readonly IdentityTrustedDeviceService $trustedDevices,
    ) {
    }

    public function issue(User $user, Request $request, string $authMethod, ?Application $application = null): array
    {
        $trusted = config('identity.features.trusted_devices', true)
            ? $this->trustedDevices->resolve($user, $request)
            : null;
        $assessment = $this->risk->assess($user, $request, $trusted);
        $context = $assessment['context'];
        $absoluteMinutes = max((int) config('identity.session.absolute_ttl_minutes', 43200), 5);
        $idleMinutes = $this->idleTtlMinutes($user);

        $session = IdentitySession::query()->create([
            'session_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'app_id' => $application?->id,
            'auth_method' => $authMethod,
            'device_label' => $context['label'],
            'ip_address' => $context['ip'],
            'user_agent' => $context['user_agent'],
            'risk_score' => $assessment['score'],
            'risk_reasons' => $assessment['reasons'],
            'trusted_device_id' => $trusted?->id,
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addMinutes($idleMinutes),
            'expires_at' => now()->addMinutes(max((int) config('identity.session.ttl_minutes', 43200), 5)),
            'absolute_expires_at' => now()->addMinutes($absoluteMinutes),
        ]);

        $ttl = max((int) config('identity.access_token_ttl_minutes', 30), 5);
        $factory = auth('api')->factory();
        $previousTtl = $factory->getTTL();
        $factory->setTTL($ttl);

        try {
            $token = auth('api')->claims([
                'sid' => $session->session_id,
                'amr' => [$authMethod],
                'ver' => max((int) ($user->auth_version ?? 1), 1),
                'risk' => $assessment['score'],
            ])->login($user);
        } finally {
            $factory->setTTL($previousTtl);
        }

        return [
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
            'session' => $this->present($session->load('application')),
            'risk' => [
                'score' => $assessment['score'],
                'level' => $assessment['level'],
                'reasons' => $assessment['reasons'],
                'step_up_required' => $assessment['step_up_required'],
            ],
        ];
    }

    public function current(): ?IdentitySession
    {
        try {
            $sid = auth('api')->payload()->get('sid');
        } catch (\Throwable) {
            return null;
        }
        if (! is_string($sid) || $sid === '') {
            return null;
        }
        return IdentitySession::query()->where('session_id', $sid)->first();
    }

    public function touch(?IdentitySession $session, Request $request): void
    {
        if (! $session) {
            return;
        }
        if (! $session->isActive()) {
            $this->revoke($session, 'session_expired');
            return;
        }

        $interval = max((int) config('identity.session.touch_interval_minutes', 5), 1);
        if ($session->last_seen_at && $session->last_seen_at->gt(now()->subMinutes($interval))) {
            return;
        }

        $user = $session->user ?: User::query()->find($session->user_id);
        $idleMinutes = $user ? $this->idleTtlMinutes($user) : max((int) config('identity.session.idle_ttl_minutes', 10080), 5);
        $assessment = $user ? $this->risk->assess($user, $request, $session->trustedDevice) : null;

        if ($assessment && $assessment['score'] >= (int) config('identity.step_up.critical_risk_score', 80)) {
            $this->revoke($session, 'critical_risk_context');
            return;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addMinutes($idleMinutes),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'risk_score' => $assessment['score'] ?? $session->risk_score,
            'risk_reasons' => $assessment['reasons'] ?? $session->risk_reasons,
        ])->save();
    }

    public function revoke(IdentitySession $session, string $reason = 'user_revoked'): void
    {
        if ($session->revoked_at) {
            return;
        }
        $session->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
    }

    public function revokeAll(User $user, string $reason = 'user_revoked_all', ?string $exceptSessionId = null): int
    {
        return IdentitySession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($query) => $query->where('session_id', '!=', $exceptSessionId))
            ->update(['revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now()]);
    }

    public function rename(User $user, string $sessionId, string $nickname): IdentitySession
    {
        $session = IdentitySession::query()->where('user_id', $user->id)->where('session_id', $sessionId)->firstOrFail();
        $session->forceFill(['nickname' => trim($nickname) ?: null])->save();
        return $session;
    }

    public function activeFor(User $user)
    {
        return IdentitySession::query()
            ->with(['application:id,name,slug,url', 'trustedDevice'])
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('absolute_expires_at')->orWhere('absolute_expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('idle_expires_at')->orWhere('idle_expires_at', '>', now()))
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function present(IdentitySession $session): array
    {
        $application = $session->relationLoaded('application') && $session->application
            ? $session->application->only(['id', 'name', 'slug', 'url'])
            : null;
        $activeNow = $session->last_seen_at?->gt(now()->subMinutes(5)) ?? false;
        $display = $session->nickname ?: $this->devices->display([
            'label' => $session->device_label ?: 'Dispositivo',
            'country' => $session->trustedDevice?->country_code,
        ], $application['name'] ?? null, $activeNow);

        return [
            'id' => $session->session_id,
            'application' => $application,
            'auth_method' => $session->auth_method,
            'device' => $session->device_label,
            'nickname' => $session->nickname,
            'display_name' => $display,
            'trusted' => (bool) $session->trusted_device_id,
            'risk' => ['score' => (int) $session->risk_score, 'reasons' => $session->risk_reasons ?: []],
            'ip' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'idle_expires_at' => $session->idle_expires_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'absolute_expires_at' => $session->absolute_expires_at?->toIso8601String(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
        ];
    }

    private function idleTtlMinutes(User $user): int
    {
        if ($user->hasProfile('Administrador')) {
            return max((int) config('identity.session.admin_idle_ttl_minutes', 120), 5);
        }
        return max((int) config('identity.session.idle_ttl_minutes', 10080), 5);
    }
}
