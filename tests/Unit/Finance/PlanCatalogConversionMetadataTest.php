<?php

namespace Tests\Unit\Finance;

use App\Domain\Finance\Services\PlanCatalogService;
use Tests\TestCase;

class PlanCatalogConversionMetadataTest extends TestCase
{
    public function test_configured_plan_preserves_conversion_metadata(): void
    {
        config()->set('subscriptions.currency', 'BRL');
        config()->set('subscriptions.billing_interval', 'month');
        config()->set('subscriptions.billing_interval_count', 1);
        config()->set('subscriptions.applications.revenue-test', [
            'subscription_enabled' => true,
            'plans' => [[
                'code' => 'pro',
                'name' => 'Pro',
                'price_cents' => 6990,
                'recommended' => true,
                'features' => ['Agenda', '', 'Automacoes'],
                'trial_days' => 14,
                'entitlements' => ['application_access' => true],
            ]],
        ]);

        $plan = app(PlanCatalogService::class)->find('REVENUE-TEST', 'pro');

        $this->assertNotNull($plan);
        $this->assertSame(6990, $plan['price_cents']);
        $this->assertTrue($plan['recommended']);
        $this->assertSame(['Agenda', 'Automacoes'], $plan['features']);
        $this->assertSame(14, $plan['trial_days']);
        $this->assertTrue($plan['entitlements']['application_access']);
    }

    public function test_missing_conversion_metadata_has_safe_defaults(): void
    {
        config()->set('subscriptions.applications.revenue-test', [
            'subscription_enabled' => true,
            'plans' => [[
                'code' => 'starter',
                'name' => 'Starter',
                'price_cents' => 3990,
            ]],
        ]);

        $plan = app(PlanCatalogService::class)->find('revenue-test', 'starter');

        $this->assertFalse($plan['recommended']);
        $this->assertSame([], $plan['features']);
        $this->assertSame(0, $plan['trial_days']);
    }
}
