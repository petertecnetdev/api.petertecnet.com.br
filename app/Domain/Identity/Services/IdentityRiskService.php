<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentitySession;
use Illuminate\Http\Request;

class IdentityRiskService
{
    public function hasHighRiskContextChange(IdentitySession $session, Request $request): bool
    {
        if (! $session->device_label || ! $request->userAgent()) {
            return false;
        }

        return ! hash_equals(
            $this->deviceLabel($session->user_agent),
            $this->deviceLabel($request->userAgent())
        );
    }

    public function ipChanged(IdentitySession $session, Request $request): bool
    {
        $current = (string) $request->ip();
        return $current !== '' && $session->ip_address && ! hash_equals((string) $session->ip_address, $current);
    }

    public function deviceLabel(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        $platform = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') ? 'iOS'
            : (str_contains($ua, 'android') ? 'Android'
                : (str_contains($ua, 'windows') ? 'Windows'
                    : (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') ? 'macOS'
                        : (str_contains($ua, 'linux') ? 'Linux' : 'Device'))));

        $browser = str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'opr/') ? 'Opera'
                : (str_contains($ua, 'firefox/') ? 'Firefox'
                    : (str_contains($ua, 'chrome/') ? 'Chrome'
                        : (str_contains($ua, 'safari/') ? 'Safari' : 'Browser'))));

        return $platform.' · '.$browser;
    }
}
