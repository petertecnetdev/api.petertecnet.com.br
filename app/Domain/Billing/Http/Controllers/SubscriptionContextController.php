<?php

namespace App\Domain\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class SubscriptionContextController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function plans()
    {
        $plans = Plan::query()
            ->forApplication($this->context->id())
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => $this->planPayload($plan));

        return response()->json(['data' => $plans]);
    }

    public function current(Request $request)
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->forApplication($this->context->id())
            ->where('user_id', $request->user()->id)
            ->active()
            ->latest('current_period_ends_at')
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $subscription ? $this->subscriptionPayload($subscription) : null,
        ]);
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
