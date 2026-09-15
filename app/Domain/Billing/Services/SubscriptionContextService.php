<?php

namespace App\Domain\Billing\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\ApplicationContext;

final class SubscriptionContextService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function plans(): array
    {
        return Plan::query()
            ->forApplication($this->context->id())
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => $this->planPayload($plan))
            ->values()
            ->all();
    }

    public function currentForUser(int $userId): ?array
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->forApplication($this->context->id())
            ->where('user_id', $userId)
            ->active()
            ->latest('current_period_ends_at')
            ->latest('id')
            ->first();

        return $subscription ? $this->subscriptionPayload($subscription) : null;
    }

    private function planPayload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'billing_interval_count' => $plan->billing_interval_count,
            'entitlements' => $plan->entitlements ?? [],
        ];
    }

    private function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'starts_at' => $subscription->starts_at,
            'current_period_starts_at' => $subscription->current_period_starts_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'cancelled_at' => $subscription->cancelled_at,
            'plan' => $subscription->plan ? $this->planPayload($subscription->plan) : null,
            'entitlements' => $subscription->plan?->entitlements ?? [],
        ];
    }
}
