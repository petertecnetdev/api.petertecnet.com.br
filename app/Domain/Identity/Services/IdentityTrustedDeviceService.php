<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class IdentityTrustedDeviceService
{
    public function __construct(private readonly IdentityDeviceService $devices)
    {
    }

    public function trust(User $user, Request $request, ?string $name = null): array
    {
        $raw = Str::random(96);
        $context = $this->devices->context($request);
        $device = IdentityTrustedDevice::query()->create([
            'device_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'secret_hash' => hash('sha256', $raw),
            'name' => trim((string) $name) ?: $context['label'],
            'platform' => $context['platform'],
            'browser' => $context['browser'],
            'ip_address' => $context['ip'],
            'country_code' => $context['country'],
            'user_agent' => $context['user_agent'],
            'last_seen_at' => now(),
            'trusted_at' => now(),
            'expires_at' => now()->addDays(max((int) config('identity.trusted_devices.ttl_days', 90), 1)),
            'metadata' => ['fingerprint' => $this->devices->fingerprint($request)],
        ]);

        return ['device' => $device, 'cookie' => $this->cookie($raw)];
    }

    public function resolve(User $user, Request $request): ?IdentityTrustedDevice
    {
        $raw = (string) $request->cookie($this->cookieName(), '');
        if ($raw === '') {
            return null;
        }

        $device = IdentityTrustedDevice::query()
            ->where('user_id', $user->id)
            ->where('secret_hash', hash('sha256', $raw))
            ->first();

        if (! $device || ! $device->isActive()) {
            return null;
        }

        $storedFingerprint = (string) data_get($device->metadata, 'fingerprint', '');
        if ($storedFingerprint !== '' && ! hash_equals($storedFingerprint, $this->devices->fingerprint($request))) {
            $this->revoke($device, 'device_fingerprint_changed');
            return null;
        }

        $context = $this->devices->context($request);
        $device->forceFill([
            'last_seen_at' => now(),
            'ip_address' => $context['ip'],
            'country_code' => $context['country'],
        ])->save();

        return $device;
    }

    public function revoke(IdentityTrustedDevice $device, string $reason = 'user_revoked'): void
    {
        if ($device->revoked_at) {
            return;
        }
        $device->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
    }

    public function revokeAll(User $user, string $reason = 'user_revoked_all'): int
    {
        return IdentityTrustedDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now()]);
    }

    public function activeFor(User $user)
    {
        return IdentityTrustedDevice::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function present(IdentityTrustedDevice $device): array
    {
        return [
            'id' => $device->device_id,
            'name' => $device->name,
            'platform' => $device->platform,
            'browser' => $device->browser,
            'country' => $device->country_code,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'trusted_at' => $device->trusted_at?->toIso8601String(),
            'expires_at' => $device->expires_at?->toIso8601String(),
        ];
    }

    public function cookie(string $raw)
    {
        return Cookie::make(
            $this->cookieName(),
            $raw,
            max((int) config('identity.trusted_devices.ttl_days', 90), 1) * 1440,
            '/',
            null,
            true,
            true,
            false,
            'lax'
        );
    }

    public function forgetCookie()
    {
        return Cookie::forget($this->cookieName(), '/', null);
    }

    private function cookieName(): string
    {
        return (string) config('identity.trusted_devices.cookie', 'peter_trusted_device');
    }
}
