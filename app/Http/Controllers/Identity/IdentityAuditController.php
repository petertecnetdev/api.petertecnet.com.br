<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $user = $request->user('api');

        $rows = EcosystemAuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'like', 'identity.%')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (EcosystemAuditLog $row) {
                $metadata = is_array($row->after) ? $row->after : [];
                $type = str_starts_with((string) $row->action, 'identity.')
                    ? substr((string) $row->action, strlen('identity.'))
                    : (string) $row->action;

                $outcome = str_contains($type, 'failed') || str_contains($type, 'rejected')
                    ? 'failure'
                    : (str_contains($type, 'challenge') ? 'challenge_required' : 'success');

                return [
                    'id' => $row->id,
                    'type' => $type,
                    'outcome' => $outcome,
                    'occurred_at' => $row->created_at?->toIso8601String(),
                    'ip_address' => $row->ip,
                    'user_agent' => $row->user_agent,
                    'application' => [
                        'id' => $metadata['application_id'] ?? null,
                        'slug' => $metadata['application_slug'] ?? null,
                    ],
                    'metadata' => collect($metadata)
                        ->except(['application_id', 'application_slug'])
                        ->all(),
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }
}
