<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\SubscriptionIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
            'source' => ['nullable', 'string', 'max:120'],
            'handoff_channel' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array', 'max:25'],
            'metadata.client_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'metadata.currency' => ['nullable', 'string', 'size:3'],
            'metadata.page' => ['nullable', 'string', 'max:255'],
            'metadata.referral' => ['nullable', 'string', 'max:120'],
            'metadata.campaign' => ['nullable', 'string', 'max:120'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            return response()->json([
                'message' => 'Idempotency-Key header is required and must have at most 120 characters.',
            ], 422);
        }

        $userId = (int) $request->user()->getAuthIdentifier();
        $appSlug = (string) $app->slug;

        $existing = SubscriptionIntent::query()
            ->where('application', $appSlug)
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

        $metadata = Arr::get($validated, 'metadata', []);
        $priceCents = max(0, (int) Arr::get($metadata, 'client_price_cents', 0));
        $currency = strtoupper((string) Arr::get($metadata, 'currency', 'BRL'));

        // Client pricing is captured only for funnel telemetry. Billing must resolve
        // the authoritative server-side plan price before creating a payment.
        $metadata['pricing_authoritative'] = false;

        $intent = SubscriptionIntent::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'application' => $appSlug,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'public_id' => (string) Str::uuid(),
                'plan_code' => $validated['plan_code'],
                'plan_name' => $validated['plan_code'],
                'price_cents' => $priceCents,
                'currency' => $currency,
                'billing_interval' => 'month',
                'billing_interval_count' => 1,
                'source' => Arr::get($validated, 'source'),
                'handoff_channel' => Arr::get($validated, 'handoff_channel'),
                'status' => 'created',
                'metadata' => $metadata,
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
