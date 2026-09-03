<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityFeatureService;
use App\Domain\Identity\Services\IdentityMetricsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityOperationsController extends Controller
{
    public function __construct(
        private readonly IdentityFeatureService $features,
        private readonly IdentityMetricsService $metrics,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function protocol(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => 'Peter Identity',
                'protocol_version' => (string) config('identity.protocol.version', '3.0'),
                'sdk_min_version' => (string) config('identity.protocol.sdk_min_version', '3.0.0'),
                'capabilities' => [
                    'global_sso' => true,
                    'rotating_refresh' => true,
                    'step_up' => ['password', 'totp', 'passkey'],
                    'trusted_devices' => true,
                    'local_logout' => true,
                    'global_logout' => true,
                    'passkeys' => true,
                    'two_factor' => true,
                    'legacy_token_observation' => true,
                ],
                'legacy' => [
                    'mode' => (string) config('identity.legacy_tokens.mode', 'observe'),
                    'sunset_at' => config('identity.legacy_tokens.sunset_at'),
                ],
            ],
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function observability(Request $request): JsonResponse
    {
        $minutes = (int) $request->query('minutes', 60);
        return response()->json(['success' => true, 'data' => $this->metrics->snapshot($minutes)]);
    }

    public function rollout(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->features->rollout()]);
    }

    public function updateRollout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_percentage' => ['required', 'integer', 'between:0,100'],
            'applications' => ['nullable', 'array'],
            'applications.*.enabled' => ['nullable', 'boolean'],
            'applications.*.percentage' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $rollout = $this->features->replaceRollout($data, $request->user('api'));
        $this->audit->record('rollout_updated', $request->user('api'), $request, null, ['rollout' => $rollout], true);
        return response()->json(['success' => true, 'data' => $rollout]);
    }
}
