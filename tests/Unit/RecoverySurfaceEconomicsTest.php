<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RecoverySurfaceEconomics;
use PHPUnit\Framework\TestCase;

final class RecoverySurfaceEconomicsTest extends TestCase
{
    public function test_it_measures_observed_recovery_value_per_surface_impression(): void
    {
        $orders = [
            $this->order('order-paid', 'paid', 10.0, 2.0, 'platform_collection'),
            $this->order('order-pending', 'pending', 10.0, 2.0, 'platform_collection'),
        ];
        $interactions = [
            $this->event('checkout_recovery_notification_cta_viewed', 'order-paid', 'navbar_popover'),
            $this->event('checkout_recovery_notification_cta_viewed', 'order-pending', 'navbar_popover'),
            $this->event('checkout_recovery_notification_cta_clicked', 'order-paid', 'navbar_popover'),
            $this->event('checkout_recovery_landed', 'order-paid', 'purchase_detail'),
            $this->event('checkout_recovery_resumed', 'order-paid', 'purchase_detail'),
        ];

        $surfaces = (new RecoverySurfaceEconomics())->summarize($interactions, $orders);

        self::assertCount(1, $surfaces);
        self::assertSame('navbar_popover', $surfaces[0]['surface']);
        self::assertSame(2, $surfaces[0]['cta_impressions']);
        self::assertSame(2, $surfaces[0]['cta_impression_orders']);
        self::assertSame(1, $surfaces[0]['cta_clicks']);
        self::assertSame(1, $surfaces[0]['cta_clicked_orders']);
        self::assertSame(50.0, $surfaces[0]['cta_ctr_percent']);
        self::assertSame(1, $surfaces[0]['landed_orders']);
        self::assertSame(1, $surfaces[0]['resumed_orders']);
        self::assertSame(1, $surfaces[0]['paid_orders']);
        self::assertSame(100.0, $surfaces[0]['click_to_paid_rate_percent']);
        self::assertSame(50.0, $surfaces[0]['observed_paid_orders_per_100_impressions']);
        self::assertSame(8.0, $surfaces[0]['observed_platform_contribution']);
        self::assertSame(4.0, $surfaces[0]['observed_platform_contribution_per_impression']);
        self::assertFalse($surfaces[0]['sample_is_mature']);
        self::assertSame(30, $surfaces[0]['min_impression_orders_required']);
        self::assertSame(28, $surfaces[0]['remaining_impression_orders_to_maturity']);
        self::assertNull($surfaces[0]['decision_platform_contribution_per_impression']);
    }

    public function test_surface_becomes_decision_eligible_after_thirty_unique_impression_orders(): void
    {
        $orders = [];
        $interactions = [];

        for ($i = 1; $i <= 30; $i++) {
            $publicId = 'order-'.$i;
            $orders[] = $this->order($publicId, $i === 1 ? 'paid' : 'pending', 10.0, 2.0, 'platform_collection');
            $interactions[] = $this->event('checkout_recovery_notification_cta_viewed', $publicId, 'navbar_popover');
        }
        $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', 'order-1', 'navbar_popover');

        $surface = (new RecoverySurfaceEconomics())->summarize($interactions, $orders)[0];

        self::assertTrue($surface['sample_is_mature']);
        self::assertSame(0, $surface['remaining_impression_orders_to_maturity']);
        self::assertSame($surface['observed_platform_contribution_per_impression'], $surface['decision_platform_contribution_per_impression']);
    }

    public function test_mature_surface_ranks_ahead_of_immature_surface_even_if_immature_observed_value_is_higher(): void
    {
        $orders = [];
        $interactions = [];

        for ($i = 1; $i <= 30; $i++) {
            $publicId = 'mature-'.$i;
            $orders[] = $this->order($publicId, $i === 1 ? 'paid' : 'pending', 10.0, 0.0, 'producer_collection');
            $interactions[] = $this->event('checkout_recovery_notification_cta_viewed', $publicId, 'notifications_page');
        }
        $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', 'mature-1', 'notifications_page');

        $orders[] = $this->order('immature-paid', 'paid', 100.0, 0.0, 'producer_collection');
        $interactions[] = $this->event('checkout_recovery_notification_cta_viewed', 'immature-paid', 'navbar_popover');
        $interactions[] = $this->event('checkout_recovery_notification_cta_clicked', 'immature-paid', 'navbar_popover');

        $surfaces = (new RecoverySurfaceEconomics())->summarize($interactions, $orders);

        self::assertSame('notifications_page', $surfaces[0]['surface']);
        self::assertTrue($surfaces[0]['sample_is_mature']);
        self::assertSame('navbar_popover', $surfaces[1]['surface']);
        self::assertFalse($surfaces[1]['sample_is_mature']);
        self::assertNull($surfaces[1]['decision_platform_contribution_per_impression']);
    }

    public function test_paid_order_is_attributed_only_to_latest_clicked_surface(): void
    {
        $orders = [$this->order('order-paid', 'paid', 5.0, 0.0, 'producer_collection')];
        $interactions = [
            $this->event('checkout_recovery_notification_cta_viewed', 'order-paid', 'navbar_popover'),
            $this->event('checkout_recovery_notification_cta_clicked', 'order-paid', 'navbar_popover'),
            $this->event('checkout_recovery_notification_cta_viewed', 'order-paid', 'notifications_page'),
            $this->event('checkout_recovery_notification_cta_clicked', 'order-paid', 'notifications_page'),
            $this->event('checkout_recovery_landed', 'order-paid', 'purchase_detail'),
        ];

        $surfaces = collect((new RecoverySurfaceEconomics())->summarize($interactions, $orders))->keyBy('surface');

        self::assertSame(0, $surfaces['navbar_popover']['paid_orders']);
        self::assertSame(0.0, $surfaces['navbar_popover']['observed_platform_contribution']);
        self::assertSame(1, $surfaces['notifications_page']['paid_orders']);
        self::assertSame(5.0, $surfaces['notifications_page']['observed_platform_contribution']);
        self::assertSame(1, $surfaces['notifications_page']['landed_orders']);
    }

    public function test_it_ignores_events_for_orders_outside_the_scoped_order_set(): void
    {
        $surfaces = (new RecoverySurfaceEconomics())->summarize([
            $this->event('checkout_recovery_notification_cta_viewed', 'other-order', 'navbar_popover'),
            $this->event('checkout_recovery_notification_cta_clicked', 'other-order', 'navbar_popover'),
        ], [
            $this->order('scoped-order', 'paid', 10.0, 1.0, 'platform_collection'),
        ]);

        self::assertSame([], $surfaces);
    }

    /** @return array<string, mixed> */
    private function order(string $publicId, string $status, float $platformFee, float $processorFee, string $settlementMode): array
    {
        return [
            'public_id' => $publicId,
            'status' => $status,
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'metadata' => ['settlement_mode' => $settlementMode],
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $type, string $publicId, string $entrypoint): array
    {
        return [
            'interaction_type' => 'frontend_'.$type,
            'content' => [
                'metadata' => [
                    'order_public_id' => $publicId,
                    'recovery_entrypoint' => $entrypoint,
                ],
            ],
        ];
    }
}
