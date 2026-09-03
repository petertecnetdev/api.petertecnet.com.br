<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityDevice;
use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityDeviceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityDeviceController extends Controller
{
    public function __construct(
        private readonly IdentityDeviceService $devices,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = IdentityDevice::query()
            ->with('lastApplication:id,name,slug,url')
            ->where('user_id', $request->user('api')->id)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (IdentityDevice $device) => array_merge($this->devices->present($device), [
                'active_sessions' => IdentitySession::query()->where('device_id', $device->id)->whereNull('revoked_at')->count(),
                'active_global_sessions' => IdentityGlobalSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->count(),
            ]));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function rename(Request $request, string $deviceId): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:180']]);
        $device = $this->owned($request, $deviceId);
        $this->devices->rename($device, $data['name']);
        $this->audit->record('device_renamed', $request->user('api'), $request, $device->lastApplication, ['device_id' => $device->device_id]);

        return response()->json(['success' => true, 'data' => $this->devices->present($device->fresh('lastApplication'))]);
    }

    public function trust(Request $request, string $deviceId): JsonResponse
    {
        $data = $request->validate(['trusted' => ['required', 'boolean']]);
        $device = $this->owned($request, $deviceId);
        $this->devices->setTrusted($device, (bool) $data['trusted']);
        $this->audit->record('device_trust_changed', $request->user('api'), $request, $device->lastApplication, [
            'device_id' => $device->device_id,
            'trusted' => (bool) $data['trusted'],
        ], true);

        return response()->json(['success' => true, 'data' => $this->devices->present($device->fresh('lastApplication'))]);
    }

    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        $device = $this->owned($request, $deviceId);
        $now = now();
        $appCount = IdentitySession::query()->where('user_id', $request->user('api')->id)->where('device_id', $device->id)->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoke_reason' => 'device_revoked', 'updated_at' => $now]);
        $globalCount = IdentityGlobalSession::query()->where('user_id', $request->user('api')->id)->where('device_id', $device->id)->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoke_reason' => 'device_revoked', 'updated_at' => $now]);
        $this->devices->setTrusted($device, false);
        $this->audit->record('device_revoked', $request->user('api'), $request, $device->lastApplication, [
            'device_id' => $device->device_id,
            'application_sessions' => $appCount,
            'global_sessions' => $globalCount,
        ], true);

        return response()->json(['success' => true, 'revoked' => $appCount + $globalCount]);
    }

    private function owned(Request $request, string $deviceId): IdentityDevice
    {
        return IdentityDevice::query()
            ->with('lastApplication:id,name,slug,url')
            ->where('user_id', $request->user('api')->id)
            ->where('device_id', $deviceId)
            ->firstOrFail();
    }
}
