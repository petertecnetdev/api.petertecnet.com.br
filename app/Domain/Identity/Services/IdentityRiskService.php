<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;

class IdentityRiskService
{
    public function __construct(private readonly IdentityDeviceService $devices)
    {
    }

    public function assess(User $user, Request $request, ?IdentityTrustedDevice $trustedDevice = null, int $failedAttempts = 0): array
    {
        $context = $this->devices->context($request);
        $score = 0;
        $reasons = [];

        if (! $trustedDevice) {
            $score += 15;
            $reasons[] = 'untrusted_device';
        } else {
            $score -= 20;
        }

        $previous = IdentityGlobalSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->latest('last_seen_at')
            ->first();

        if ($previous) {
            if ($previous->user_agent && ! $this->devices->samePlatformBrowser($previous->user_agent, $request)) {
                $score += 50;
                $reasons[] = 'browser_or_platform_changed';
            }

            if ($previous->ip_address && $context['ip'] && ! $this->sameNetwork((string) $previous->ip_address, (string) $context['ip'])) {
                $score += 10;
                $reasons[] = 'network_changed';
            }

            if ($previous->country_code && $context['country'] && $previous->country_code !== $context['country']) {
                $score += 25;
                $reasons[] = 'country_changed';
                if ($previous->last_seen_at && $previous->last_seen_at->gt(now()->subHours(2))) {
                    $score += 30;
                    $reasons[] = 'impossible_travel_window';
                }
            }
        }

        if ($failedAttempts > 0) {
            $score += min(30, $failedAttempts * 5);
            $reasons[] = 'recent_failed_attempts';
        }

        $score = max(0, min(100, $score));
        return [
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
            'level' => $score >= (int) config('identity.step_up.critical_risk_score', 80) ? 'critical'
                : ($score >= (int) config('identity.step_up.high_risk_score', 55) ? 'high'
                    : ($score >= 30 ? 'medium' : 'low')),
            'step_up_required' => $score >= (int) config('identity.step_up.high_risk_score', 55),
            'context' => $context,
        ];
    }

    public function hasHighRiskContextChange(IdentitySession $session, Request $request): bool
    {
        if (! $session->device_label || ! $request->userAgent()) {
            return false;
        }
        return ! $this->devices->samePlatformBrowser($session->user_agent, $request);
    }

    public function ipChanged(IdentitySession $session, Request $request): bool
    {
        $current = (string) $request->ip();
        return $current !== '' && $session->ip_address && ! hash_equals((string) $session->ip_address, $current);
    }

    public function deviceLabel(?string $userAgent): string
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => (string) $userAgent]);
        return $this->devices->context($request)['label'];
    }

    private function sameNetwork(string $left, string $right): bool
    {
        if (str_contains($left, ':') || str_contains($right, ':')) {
            return substr($left, 0, 12) === substr($right, 0, 12);
        }
        $a = explode('.', $left);
        $b = explode('.', $right);
        return count($a) === 4 && count($b) === 4 && array_slice($a, 0, 3) === array_slice($b, 0, 3);
    }
}
