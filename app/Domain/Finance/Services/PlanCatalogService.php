<?php

namespace App\Domain\Finance\Services;

use App\Models\Plan;

class PlanCatalogService
{
    public function find(string $application, string $planCode): ?array
    {
        $application = strtolower(trim($application));
        $planCode = trim($planCode);

        if ($application === '' || $planCode === '') {
            return null;
        }

        $definition = config("subscriptions.applications.{$application}");
        if (is_array($definition) && ($definition['subscription_enabled'] ?? false)) {
            $configured = collect($definition['plans'] ?? [])->first(
                fn (array $candidate) => (string) ($candidate['code'] ?? '') === $planCode
            );

            if (is_array($configured)) {
                return $this->normalizeConfiguredPlan($configured);
            }
        }

        $plan = Plan::query()
            ->whereHas('application', fn ($query) => $query
                ->where('slug', $application)
                ->where('is_active', true))
            ->active()
            ->where('code', $planCode)
            ->first();

        return $plan ? $this->normalizeModelPlan($plan) : null;
    }

    public function all(string $application): array
    {
        $application = strtolower(trim($application));
        if ($application === '') {
            return [];
        }

        $plans = [];
        $definition = config("subscriptions.applications.{$application}");

        if (is_array($definition) && ($definition['subscription_enabled'] ?? false)) {
            foreach ($definition['plans'] ?? [] as $configured) {
                if (is_array($configured) && isset($configured['code'])) {
                    $plans[(string) $configured['code']] = $this->normalizeConfiguredPlan($configured);
                }
            }
        }

        Plan::query()
            ->whereHas('application', fn ($query) => $query
                ->where('slug', $application)
                ->where('is_active', true))
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->each(function (Plan $plan) use (&$plans): void {
                $plans[$plan->code] ??= $this->normalizeModelPlan($plan);
            });

        return array_values($plans);
    }

    private function normalizeConfiguredPlan(array $plan): array
    {
        return [
            'code' => (string) $plan['code'],
            'name' => (string) ($plan['name'] ?? $plan['code']),
            'description' => $plan['description'] ?? null,
            'price_cents' => max(0, (int) ($plan['price_cents'] ?? 0)),
            'currency' => (string) ($plan['currency'] ?? config('subscriptions.currency', 'BRL')),
            'billing_interval' => (string) ($plan['billing_interval'] ?? config('subscriptions.billing_interval', 'month')),
            'billing_interval_count' => max(1, (int) ($plan['billing_interval_count'] ?? config('subscriptions.billing_interval_count', 1))),
            'recommended' => (bool) ($plan['recommended'] ?? false),
            'features' => array_values(array_filter(
                is_array($plan['features'] ?? null) ? $plan['features'] : [],
                fn ($feature) => is_string($feature) && trim($feature) !== ''
            )),
            'trial_days' => max(0, (int) ($plan['trial_days'] ?? 0)),
            'entitlements' => $plan['entitlements'] ?? [],
            'metadata' => $plan['metadata'] ?? [],
        ];
    }

    private function normalizeModelPlan(Plan $plan): array
    {
        $metadata = is_array($plan->metadata) ? $plan->metadata : [];

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_cents' => max(0, (int) round(((float) $plan->price) * 100)),
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'billing_interval_count' => max(1, (int) $plan->billing_interval_count),
            'recommended' => (bool) ($metadata['recommended'] ?? false),
            'features' => array_values(array_filter(
                is_array($metadata['features'] ?? null) ? $metadata['features'] : [],
                fn ($feature) => is_string($feature) && trim($feature) !== ''
            )),
            'trial_days' => max(0, (int) ($metadata['trial_days'] ?? 0)),
            'entitlements' => $plan->entitlements ?? [],
            'metadata' => $metadata,
        ];
    }
}
