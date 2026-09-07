<?php

namespace Tests\Feature;

use App\Domain\Acquisition\Services\AcquisitionCommissionPolicy;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AcquisitionCommissionMarginPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_ceiling_preserves_default_platform_margin(): void
    {
        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
        config()->set('platform.applications.cutinapp.commerce.platform_fee_percent', 10);

        $context = app(ApplicationContext::class);
        $context->set($application);
        $policy = app(AcquisitionCommissionPolicy::class);

        $economics = $policy->economics();

        $this->assertSame(10.0, $economics['platform_fee_percentage']);
        $this->assertSame(1.0, $economics['minimum_retained_margin_percentage']);
        $this->assertSame(0.0, $economics['processing_reserve_percentage']);
        $this->assertSame(9.0, $economics['max_commission_percentage']);
        $this->assertSame(9.0, $policy->maxPercentage());
    }

    public function test_custom_retained_margin_reduces_commission_ceiling(): void
    {
        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
        config()->set('platform.applications.cutinapp.commerce.platform_fee_percent', 10);
        config()->set('platform.applications.cutinapp.commerce.acquisition_min_platform_margin_percent', 2.5);

        $context = app(ApplicationContext::class);
        $context->set($application);
        $policy = app(AcquisitionCommissionPolicy::class);

        $this->assertSame(7.5, $policy->maxPercentage());

        $this->expectException(ValidationException::class);
        $policy->assertPercentageAllowed(8.0);
    }
}
