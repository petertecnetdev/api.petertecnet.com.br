<?php

namespace Tests\Feature;

use Tests\TestCase;

class SubscriptionPlanCatalogTest extends TestCase
{
    public function test_rasoio_monthly_prices_are_published(): void
    {
        $response = $this->getJson('/api/v1/apps/rasoio/subscription-plans');

        $response->assertOk()
            ->assertJsonPath('data.subscription_enabled', true)
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.plans.0.price_cents', 3990)
            ->assertJsonPath('data.plans.1.price_cents', 6990)
            ->assertJsonPath('data.plans.2.price_cents', 11990);
    }

    public function test_plat_monthly_prices_are_published(): void
    {
        $this->getJson('/api/v1/apps/plat/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.plans.0.price_cents', 4990)
            ->assertJsonPath('data.plans.1.price_cents', 8990)
            ->assertJsonPath('data.plans.2.price_cents', 14990);
    }

    public function test_payflow_monthly_prices_are_published(): void
    {
        $this->getJson('/api/v1/apps/payflow/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.plans.0.price_cents', 3990)
            ->assertJsonPath('data.plans.1.price_cents', 7990)
            ->assertJsonPath('data.plans.2.price_cents', 14990);
    }

    public function test_kryvion_is_freemium(): void
    {
        $this->getJson('/api/v1/apps/kryvion/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.freemium', true)
            ->assertJsonPath('data.plans.0.price_cents', 0)
            ->assertJsonPath('data.plans.1.price_cents', 2990)
            ->assertJsonPath('data.plans.2.price_cents', 5990);
    }

    public function test_locaio_monthly_prices_are_published(): void
    {
        $this->getJson('/api/v1/apps/locaio/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.plans.0.price_cents', 3990)
            ->assertJsonPath('data.plans.1.price_cents', 6990)
            ->assertJsonPath('data.plans.2.price_cents', 11990);
    }

    public function test_cutinapp_has_no_mandatory_subscription(): void
    {
        $this->getJson('/api/v1/apps/cutinapp/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.subscription_enabled', false)
            ->assertJsonPath('data.monetization_model', 'transaction_fee')
            ->assertJsonCount(0, 'data.plans');
    }

    public function test_business_bundle_is_published(): void
    {
        $this->getJson('/api/v1/subscription-bundles')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'peter-tecnet-business')
            ->assertJsonPath('data.0.price_cents', 12990);
    }
}
