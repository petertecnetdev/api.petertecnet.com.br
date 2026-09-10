<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Models\Application;
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
            'metadata.client_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
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

        $appSlug = strtolower((string) $app->slug);
        $subscriptionDefinition = config("subscriptions.applications.{$appSlug}");

        if (! is_array($subscriptionDefinition) || ! ($subscriptionDefinition['subscription_enabled'] ?? false)) {
            return response()->json([
                'message' => 'Subscriptions are not enabled for this application.',
            ], 422);
        }

        $planCode = strtolower((string) $validated['plan_code']);
        $plan = collect($subscriptionDefinition['plans'] ?? [])->first(
            static fn (array $candidate): bool => strtolower((string) ($candidate['code'] ?? '')) === $planCode
        );

        if (! is_array($plan)) {
            return response()->json([
                'message' => 'The selected subscription plan is not available for this application.',
                'errors' => ['plan_code' => ['The selected subscription plan is invalid.']],
            ], 422);
        }

        $userId = (int) $request->user()->getAuthIdentifier();

        $existing = SubscriptionIntent::query()
            ->where('application', $appSlug)
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->plan_code !== $planCode) {
                return response()->json([
                    'message' => 'Idempotency-Key already used for a different subscription plan.',
                ], 409);
            }

            return response()->json(['data' => $existing]);
        }

        $metadata = Arr::get($validated, 'metadata', []);
        // Browser-provided pricing is telemetry only. Billing always uses the
        // authoritative server-side catalog in config/subscriptions.php.
        $metadata['pricing_authoritative'] = false;

        $priceCents = max(0, (int) ($plan['price_cents'] ?? 0));
        $currency = strtoupper((string) config('subscriptions.currency', 'BRL'));
        $billingInterval = (string) ($plan['billing_interval'] ?? config('subscriptions.billing_interval', 'month'));
        $billingIntervalCount = max(1, (int) ($plan['billing_interval_count'] ?? config('subscriptions.billing_interval_count', 1)));

        $intent = SubscriptionIntent::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'application' => $appSlug,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'public_id' => (string) Str::uuid(),
                'plan_code' => $planCode,
                'plan_name' => (string) ($plan['name'] ?? $planCode),
                'price_cents' => $priceCents,
                'currency' => $currency,
                'billing_interval' => $billingInterval,
                'billing_interval_count' => $billingIntervalCount,
                'source' => Arr::get($validated, 'source'),
                'handoff_channel' => Arr::get($validated, 'handoff_channel'),
                'status' => 'created',
                'metadata' => $metadata,
            ]
        );

        if (! $intent->wasRecentlyCreated && $intent->plan_code !== $planCode) {
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
