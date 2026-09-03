<?php

namespace App\Domain\Identity\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdentityDeviceService
{
    public function context(Request $request): array
    {
        $ua = (string) $request->userAgent();
        [$platform, $browser] = $this->parse($ua);
        $country = Str::upper(trim((string) ($request->header('CF-IPCountry') ?: $request->header('X-Country-Code'))));
        if (strlen($country) > 8) {
            $country = '';
        }

        return [
            'platform' => $platform,
            'browser' => $browser,
            'label' => $browser.' no '.$platform,
            'ip' => $request->ip(),
            'country' => $country !== '' ? $country : null,
            'user_agent' => $ua,
            'language' => trim((string) $request->header('Accept-Language')),
        ];
    }

    public function display(array $context, ?string $application = null, bool $activeNow = false): string
    {
        $parts = [trim((string) ($context['label'] ?? 'Dispositivo'))];
        if (! empty($context['country'])) {
            $parts[] = (string) $context['country'];
        }
        if ($application) {
            $parts[] = $application;
        }
        if ($activeNow) {
            $parts[] = 'ativo agora';
        }
        return implode(' — ', array_filter($parts));
    }

    public function fingerprint(Request $request): string
    {
        $context = $this->context($request);
        return hash('sha256', implode('|', [
            Str::lower((string) $context['platform']),
            Str::lower((string) $context['browser']),
            Str::lower((string) $context['language']),
        ]));
    }

    public function samePlatformBrowser(?string $storedUserAgent, Request $request): bool
    {
        [$storedPlatform, $storedBrowser] = $this->parse((string) $storedUserAgent);
        $context = $this->context($request);
        return hash_equals(Str::lower($storedPlatform), Str::lower((string) $context['platform']))
            && hash_equals(Str::lower($storedBrowser), Str::lower((string) $context['browser']));
    }

    private function parse(string $userAgent): array
    {
        $ua = Str::lower($userAgent);
        $platform = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') ? 'iOS'
            : (str_contains($ua, 'android') ? 'Android'
                : (str_contains($ua, 'windows') ? 'Windows'
                    : (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') ? 'macOS'
                        : (str_contains($ua, 'linux') ? 'Linux' : 'Dispositivo'))));

        $browser = str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'opr/') ? 'Opera'
                : (str_contains($ua, 'firefox/') ? 'Firefox'
                    : (str_contains($ua, 'chrome/') ? 'Chrome'
                        : (str_contains($ua, 'safari/') ? 'Safari' : 'Navegador'))));

        return [$platform, $browser];
    }
}
