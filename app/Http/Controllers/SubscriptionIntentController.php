<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\SubscriptionIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SubscriptionIntentController extends Controller
{
    public function store(Request $request, string $application): JsonResponse
    {
        $app = Application::query()
            ->where('slug', $application)
            ->where('is_active', true)
            ->firstOrFail();

        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'],
            'source' => ['nullable', 'string', 'max:80'],
            'handoff_channel' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array', 'max:25'],
            'metadata.client_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'metadata.currency' => ['nullable', 'string', 'size:3'],
            'metadata.page' => ['nullable', 'string', 'max:255'],
            'metadata.referral' => ['nullable', 'string', 'max:120'],
            'metadata.campaign' => ['nullable', 'string', 'max:120'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            return response()->json([
                'message' => 'Idempotency-Key header is required and must have at most 191 characters.',
            ], 422);
        }

        $userId = (int) $request->user()->getAuthIdentifier();

        $existing = SubscriptionIntent::query()
            ->where('application_id', $app->id)
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->plan_code !== $validated['plan_code']) {
                return response()->json([
                    'message' => 'Idempotency-Key already used for a different subscription plan.',
                ], 409);
            }

            return response()->json(['data' => $existing]);
        }

        $intent = SubscriptionIntent::query()->firstOrCreate(
            [
                'application_id' => $app->id,
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'plan_code' => $validated['plan_code'],
                'source' => Arr::get($validated, 'source'),
                'handoff_channel' => Arr::get($validated, 'handoff_channel'),
                'status' => 'pending',
                'metadata' => Arr::get($validated, 'metadata', []),
            ]
        );

        if (! $intent->wasRecentlyCreated && $intent->plan_code !== $validated['plan_code']) {
            return response()->json([
                'message' => 'Idempotency-Key already used for a different subscription plan.',
            ], 409);
        }

        return response()->json(
            ['data' => $intent],
            $intent->wasRecentlyCreated ? 201 : 200
        );
    }
}
