<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Models\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class SubscriptionIntentCreationService
{
    public function create(string $application, array $validated, int $userId, string $idempotencyKey): array
    {
        $app = Application::query()
            ->where('slug', $application)
            ->where('is_active', true)
            ->firstOrFail();

        $appSlug = strtolower((string) $app->slug);
        $subscriptionDefinition = config("subscriptions.applications.{$appSlug}");

        if (! is_array($subscriptionDefinition) || ! ($subscriptionDefinition['subscription_enabled'] ?? false)) {
            return ['status' => 422, 'body' => [
                'message' => 'Subscriptions are not enabled for this application.',
            ]];
        }

        $planCode = strtolower((string) $validated['plan_code']);
        $plan = collect($subscriptionDefinition['plans'] ?? [])->first(
            static fn (array $candidate): bool => strtolower((string) ($candidate['code'] ?? '')) === $planCode
        );

        if (! is_array($plan)) {
            return ['status' => 422, 'body' => [
                'message' => 'The selected subscription plan is not available for this application.',
                'errors' => ['plan_code' => ['The selected subscription plan is invalid.']],
            ]];
        }

        $existing = SubscriptionIntent::query()
            ->where('application', $appSlug)
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->plan_code !== $planCode) {
                return ['status' => 409, 'body' => [
                    'message' => 'Idempotency-Key already used for a different subscription plan.',
                ]];
            }

            return ['status' => 200, 'body' => ['data' => $existing]];
        }

        $metadata = Arr::get($validated, 'metadata', []);
        // Browser-provided pricing is telemetry only. Billing uses server configuration.
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
            return ['status' => 409, 'body' => [
                'message' => 'Idempotency-Key already used for a different subscription plan.',
            ]];
        }

        return ['status' => $intent->wasRecentlyCreated ? 201 : 200, 'body' => ['data' => $intent]];
    }
}
