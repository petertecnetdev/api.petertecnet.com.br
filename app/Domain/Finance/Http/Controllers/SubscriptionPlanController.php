<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    public function index(string $application): JsonResponse
    {
        $application = strtolower(trim($application));
        $catalog = config('subscriptions.applications', []);
        $definition = $catalog[$application] ?? null;

        if (! is_array($definition)) {
            return response()->json([
                'message' => 'Aplicativo não encontrado no catálogo de assinaturas.',
            ], 404);
        }

        $currency = (string) config('subscriptions.currency', 'BRL');
        $billingInterval = (string) config('subscriptions.billing_interval', 'month');
        $billingIntervalCount = (int) config('subscriptions.billing_interval_count', 1);

        $plans = collect($definition['plans'] ?? [])->map(function (array $plan) use ($application, $currency, $billingInterval, $billingIntervalCount) {
            $priceCents = max(0, (int) ($plan['price_cents'] ?? 0));

            return [
                ...$plan,
                'id' => implode(':', [$application, (string) ($plan['code'] ?? 'plan'), $billingInterval]),
                'application' => $application,
                'currency' => $currency,
                'price_cents' => $priceCents,
                'price' => $priceCents / 100,
                'billing_interval' => $billingInterval,
                'billing_interval_count' => $billingIntervalCount,
            ];
        })->values();

        return response()->json([
            'data' => [
                'application' => $application,
                'name' => $definition['name'] ?? $application,
                'subscription_enabled' => (bool) ($definition['subscription_enabled'] ?? false),
                'freemium' => (bool) ($definition['freemium'] ?? false),
                'monetization_model' => $definition['monetization_model'] ?? 'subscription',
                'currency' => $currency,
                'billing_interval' => $billingInterval,
                'billing_interval_count' => $billingIntervalCount,
                'plans' => $plans,
            ],
        ]);
    }

    public function bundles(): JsonResponse
    {
        $currency = (string) config('subscriptions.currency', 'BRL');
        $billingInterval = (string) config('subscriptions.billing_interval', 'month');

        $bundles = collect(config('subscriptions.bundles', []))->map(function (array $bundle) use ($currency, $billingInterval) {
            $priceCents = max(0, (int) ($bundle['price_cents'] ?? 0));

            return [
                ...$bundle,
                'currency' => $bundle['currency'] ?? $currency,
                'billing_interval' => $bundle['billing_interval'] ?? $billingInterval,
                'price_cents' => $priceCents,
                'price' => $priceCents / 100,
            ];
        })->values();

        return response()->json(['data' => $bundles]);
    }
}
