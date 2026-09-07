<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RevenueFunnelService;
use App\Models\CommerceOrder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class RevenueFunnelSettlementProfitabilityTest extends TestCase
{
    #[Test]
    public function it_attributes_processor_cost_to_the_actual_payment_collector(): void
    {
        $orders = new Collection([
            new CommerceOrder([
                'payment_method' => 'pix',
                'total' => 100,
                'platform_fee' => 10,
                'processor_fee' => 3,
                'producer_net' => 90,
                'metadata' => ['settlement_mode' => 'automatic_split'],
            ]),
            new CommerceOrder([
                'payment_method' => 'pix',
                'total' => 100,
                'platform_fee' => 10,
                'processor_fee' => 3,
                'producer_net' => 90,
                'metadata' => ['settlement_mode' => 'platform_collection'],
            ]),
        ]);

        $method = new ReflectionMethod(RevenueFunnelService::class, 'settlementProfitability');
        $result = $method->invoke(new RevenueFunnelService(), $orders);

        $this->assertSame(3.0, $result['processor_fees_borne_by_platform']);
        $this->assertSame(3.0, $result['processor_fees_borne_by_organization']);
        $this->assertSame(177.0, $result['organization_net_after_processing']);
        $this->assertSame(88.5, $result['organization_net_margin']);
        $this->assertSame(17.0, $result['platform_contribution_after_processing']);
        $this->assertSame(8.5, $result['platform_contribution_margin']);
        $this->assertSame(0, $result['platform_loss_making_orders']);
        $this->assertSame(0.0, $result['platform_contribution_shortfall']);
        $this->assertSame(3.0, $result['platform_collection_break_even_fee_rate']);
        $this->assertSame(['automatic_split' => 1, 'platform_collection' => 1], $result['settlement_modes']);
    }

    #[Test]
    public function it_treats_legacy_unknown_settlement_conservatively_as_organization_borne(): void
    {
        $orders = new Collection([
            new CommerceOrder([
                'payment_method' => 'card',
                'total' => 50,
                'platform_fee' => 5,
                'processor_fee' => 2,
                'producer_net' => 45,
                'metadata' => [],
            ]),
        ]);

        $method = new ReflectionMethod(RevenueFunnelService::class, 'settlementProfitability');
        $result = $method->invoke(new RevenueFunnelService(), $orders);

        $this->assertSame(0.0, $result['processor_fees_borne_by_platform']);
        $this->assertSame(2.0, $result['processor_fees_borne_by_organization']);
        $this->assertSame(43.0, $result['organization_net_after_processing']);
        $this->assertSame(5.0, $result['platform_contribution_after_processing']);
        $this->assertSame(0, $result['platform_loss_making_orders']);
        $this->assertSame(0.0, $result['platform_collection_break_even_fee_rate']);
        $this->assertSame(['unknown' => 1], $result['settlement_modes']);
    }

    #[Test]
    public function it_surfaces_loss_making_platform_collections_without_blocking_sales(): void
    {
        $orders = new Collection([
            new CommerceOrder([
                'payment_method' => 'card',
                'total' => 100,
                'platform_fee' => 2,
                'processor_fee' => 4,
                'producer_net' => 98,
                'metadata' => ['settlement_mode' => 'platform_collection'],
            ]),
            new CommerceOrder([
                'payment_method' => 'card',
                'total' => 200,
                'platform_fee' => 12,
                'processor_fee' => 8,
                'producer_net' => 188,
                'metadata' => ['settlement_mode' => 'platform_collection'],
            ]),
        ]);

        $method = new ReflectionMethod(RevenueFunnelService::class, 'settlementProfitability');
        $result = $method->invoke(new RevenueFunnelService(), $orders);

        $this->assertSame(1, $result['platform_loss_making_orders']);
        $this->assertSame(100.0, $result['platform_loss_making_gross_revenue']);
        $this->assertSame(2.0, $result['platform_contribution_shortfall']);
        $this->assertSame(4.0, $result['platform_collection_break_even_fee_rate']);
        $this->assertSame(2, $result['by_payment_method']['card']['platform_loss_making_orders']);
    }
}
