<?php

namespace App\Services;

use App\Models\IdentitySession;
use Illuminate\Http\Request;

class IdentityRiskService
{
    public function assess(Request $request, IdentitySession $session): array
    {
        $device = $session->device;
        if (! $device) {
            return ['level' => 'low', 'signals' => []];
        }

        [$browser, $platform] = $this->parseUserAgent((string) $request->userAgent());
        $signals = [];
        $level = 'low';

        if ($device->platform
            && $platform !== 'Device'
            && ! hash_equals(strtolower((string) $device->platform), strtolower($platform))) {
            $signals[] = [
                'type' => 'platform_changed',
                'previous' => $device->platform,
                'current' => $platform,
                'severity' => 'high',
            ];
            $level = 'high';
        }

        if ($device->browser
            && $browser !== 'Browser'
            && ! hash_equals(strtolower((string) $device->browser), strtolower($browser))) {
            $signals[] = [
                'type' => 'browser_changed',
                'previous' => $device->browser,
                'current' => $browser,
                'severity' => 'high',
            ];
            $level = 'high';
        }

        if ($device->last_ip_address
            && $request->ip()
            && ! hash_equals((string) $device->last_ip_address, (string) $request->ip())) {
            $signals[] = [
                'type' => 'ip_changed',
                'previous' => $device->last_ip_address,
                'current' => $request->ip(),
                'severity' => 'info',
            ];
        }

        $country = $this->country($request);
        $previousCountry = data_get($device->metadata, 'last_country');
        if ($country && $previousCountry && ! hash_equals(strtoupper((string) $previousCountry), strtoupper($country))) {
            $signals[] = [
                'type' => 'country_changed',
                'previous' => $previousCountry,
                'current' => $country,
                'severity' => 'medium',
            ];
            if ($level === 'low') $level = 'medium';
        }

        return [
            'level' => $level,
            'signals' => $signals,
            'browser' => $browser,
            'platform' => $platform,
            'country' => $country,
        ];
    }

    public function remember(Request $request, IdentitySession $session, array $assessment): void
    {
        $device = $session->device;
        if (! $device) return;

        $metadata = is_array($device->metadata) ? $device->metadata : [];
        if (! empty($assessment['country'])) {
            $metadata['last_country'] = $assessment['country'];
        }

        $device->forceFill([
            'last_ip_address' => $request->ip(),
            'last_seen_at' => now(),
            'metadata' => $metadata ?: null,
        ])->save();
    }

    private function country(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country', 'X-Country-Code'] as $header) {
            $value = strtoupper(trim((string) $request->header($header, '')));
            if (preg_match('/^[A-Z]{2}$/', $value)) return $value;
        }
        return null;
    }

    private function parseUserAgent(string $ua): array
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Device',
        };

        return [$browser, $platform];
    }
}
