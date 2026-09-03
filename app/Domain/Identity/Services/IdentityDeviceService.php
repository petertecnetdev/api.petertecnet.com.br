<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityDevice;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IdentityDeviceService
{
    public function resolve(User $user, Request $request, ?Application $application = null): IdentityDevice
    {
        $header = trim((string) $request->header('X-Peter-Device', ''));
        $deviceKey = $header !== '' && strlen($header) <= 120
            ? $header
            : 'legacy-'.substr(hash('sha256', $user->id.'|'.(string) $request->userAgent()), 0, 56);

        [$platform, $browser] = $this->parseUserAgent($request->userAgent());
        $now = now();

        $device = IdentityDevice::query()->firstOrNew([
            'user_id' => $user->id,
            'device_id' => $deviceKey,
        ]);

        if (! $device->exists) {
            $device->first_seen_at = $now;
            $device->first_ip_address = $request->ip();
            $device->name = trim((string) $request->header('X-Peter-Device-Name', '')) ?: $this->defaultName($platform, $browser);
            $device->metadata = ['source' => $header !== '' ? 'sdk' : 'legacy-fallback'];
        }

        $device->forceFill([
            'last_application_id' => $application?->id,
            'platform' => $platform,
            'browser' => $browser,
            'user_agent' => $request->userAgent(),
            'last_ip_address' => $request->ip(),
            'last_seen_at' => $now,
        ])->save();

        return $device;
    }

    public function rename(IdentityDevice $device, string $name): IdentityDevice
    {
        $device->forceFill(['name' => trim($name)])->save();
        return $device->fresh('lastApplication');
    }

    public function setTrusted(IdentityDevice $device, bool $trusted): IdentityDevice
    {
        $device->forceFill([
            'trusted' => $trusted,
            'trusted_at' => $trusted ? now() : null,
        ])->save();

        return $device->fresh('lastApplication');
    }

    public function present(IdentityDevice $device): array
    {
        return [
            'id' => $device->device_id,
            'name' => $device->name ?: $this->defaultName($device->platform, $device->browser),
            'platform' => $device->platform,
            'browser' => $device->browser,
            'trusted' => (bool) $device->trusted,
            'first_ip_address' => $device->first_ip_address,
            'last_ip_address' => $device->last_ip_address,
            'first_seen_at' => $device->first_seen_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'trusted_at' => $device->trusted_at?->toIso8601String(),
            'last_application' => $device->relationLoaded('lastApplication') && $device->lastApplication
                ? $device->lastApplication->only(['id', 'name', 'slug', 'url'])
                : null,
        ];
    }

    public function sameContext(?string $expectedUserAgent, ?string $actualUserAgent): bool
    {
        if (! $expectedUserAgent || ! $actualUserAgent) {
            return true;
        }

        return $this->parseUserAgent($expectedUserAgent) === $this->parseUserAgent($actualUserAgent);
    }

    public function parseUserAgent(?string $userAgent): array
    {
        $ua = strtolower((string) $userAgent);
        $platform = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') ? 'iOS'
            : (str_contains($ua, 'android') ? 'Android'
                : (str_contains($ua, 'windows') ? 'Windows'
                    : (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') ? 'macOS'
                        : (str_contains($ua, 'linux') ? 'Linux' : 'Unknown'))));

        $browser = str_contains($ua, 'edg/') ? 'Edge'
            : (str_contains($ua, 'opr/') ? 'Opera'
                : (str_contains($ua, 'firefox/') ? 'Firefox'
                    : (str_contains($ua, 'chrome/') ? 'Chrome'
                        : (str_contains($ua, 'safari/') ? 'Safari' : 'Unknown'))));

        return [$platform, $browser];
    }

    private function defaultName(?string $platform, ?string $browser): string
    {
        $label = trim(($platform ?: 'Dispositivo').' · '.($browser ?: 'Navegador'));
        return Str::limit($label, 180, '');
    }
}
