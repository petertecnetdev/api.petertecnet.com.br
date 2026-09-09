<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\ObservedRecoveryChannelEconomics;
use PHPUnit\Framework\TestCase;

final class ObservedRecoveryChannelEconomicsTest extends TestCase
{
    public function test_it_ranks_channels_from_observed_cost_conversion_and_contribution(): void
    {
        $orders = [];
        for ($i = 0; $i < 100; $i++) {
            $orders[] = $this->order('email', 0.05, $i < 10, 'pix', 30, 10.0, 0.0);
            $orders[] = $this->order('whatsapp', 0.50, $i < 20, 'pix', 30, 10.0, 0.0);
        }

        $groups = (new ObservedRecoveryChannelEconomics())->summarize($orders);

        self::assertCount(1, $groups);
        self::assertSame('pix', $groups[0]['payment_method']);
        self::assertSame('15_60m', $groups[0]['abandonment_age_bucket']);
        self::assertSame('whatsapp', $groups[0]['recommended_channel']);
        self::assertSame('whatsapp', $groups[0]['channels'][0]['channel']);
        self::assertSame(100.0, $groups[0]['channels'][0]['cost_coverage_percent']);
        self::assertGreaterThan(
            $groups[0]['channels'][1]['expected_net_contribution_per_attempt'],
            $groups[0]['channels'][0]['expected_net_contribution_per_attempt'],
        );
    }

    public function test_it_estimates_incremental_recovery_against_control_without_recommending_control(): void
    {
        $orders = [];
        for ($i = 0; $i < 100; $i++) {
            $orders[] = $this->order('in_app', 0.0, $i < 20, 'pix', 10, 10.0, 0.0);
        }
        for ($i = 0; $i < 50; $i++) {
            $orders[] = $this->order('control', 0.0, $i < 5, 'pix', 10, 10.0, 0.0);
        }

        $group = (new ObservedRecoveryChannelEconomics())->summarize($orders)[0];
        $channel = $group['channels'][0];

        self::assertSame(50, $group['control']['attempts']);
        self::assertSame(5, $group['control']['paid_orders']);
        self::assertSame(10.0, $group['control']['natural_conversion_rate']);
        self::assertSame(30, $group['control']['minimum_attempts_for_incrementality']);
        self::assertSame('in_app', $group['recommended_channel']);
        self::assertTrue($channel['incrementality_sample_ready']);
        self::assertSame(['control_attempts' => 0, 'treatment_attempts' => 0], $channel['incrementality_sample_shortfall']);
        self::assertSame(10.0, $channel['incremental_conversion_rate_pp']);
        self::assertSame(10.0, $channel['incremental_recovered_orders_estimate']);
        self::assertSame(100.0, $channel['incremental_net_contribution_estimate']);
        self::assertSame(1.0, $channel['incremental_net_contribution_per_attempt_estimate']);
        self::assertNull($channel['incremental_roi_percent_estimate']);
        self::assertCount(1, $group['channels']);
    }

    public function test_incremental_profit_stays_unknown_until_control_and_treatment_samples_are_mature(): void
    {
        $orders = [];
        for ($i = 0; $i < 20; $i++) {
            $orders[] = $this->order('in_app', 0.0, $i < 8, 'pix', 10, 10.0, 0.0);
        }
        for ($i = 0; $i < 10; $i++) {
            $orders[] = $this->order('control', 0.0, $i < 1, 'pix', 10, 10.0, 0.0);
        }

        $channel = (new ObservedRecoveryChannelEconomics())->summarize($orders)[0]['channels'][0];

        self::assertFalse($channel['incrementality_sample_ready']);
        self::assertSame(['control_attempts' => 20, 'treatment_attempts' => 10], $channel['incrementality_sample_shortfall']);
        self::assertNull($channel['incremental_conversion_rate_pp']);
        self::assertNull($channel['incremental_recovered_orders_estimate']);
        self::assertNull($channel['incremental_net_contribution_estimate']);
        self::assertNull($channel['incremental_net_contribution_per_attempt_estimate']);
        self::assertNull($channel['incremental_roi_percent_estimate']);
    }

    public function test_incremental_roi_is_exposed_only_for_mature_paid_channel_with_complete_cost_data(): void
    {
        $orders = [];
        for ($i = 0; $i < 100; $i++) {
            $orders[] = $this->order('whatsapp', 0.50, $i < 20, 'pix', 30, 10.0, 0.0);
        }
        for ($i = 0; $i < 50; $i++) {
            $orders[] = $this->order('control', 0.0, $i < 5, 'pix', 30, 10.0, 0.0);
        }

        $channel = (new ObservedRecoveryChannelEconomics())->summarize($orders)[0]['channels'][0];

        self::assertTrue($channel['incrementality_sample_ready']);
        self::assertSame(50.0, $channel['incremental_net_contribution_estimate']);
        self::assertSame(0.5, $channel['incremental_net_contribution_per_attempt_estimate']);
        self::assertSame(100.0, $channel['incremental_roi_percent_estimate']);
    }

    public function test_it_exposes_realized_net_contribution_and_roi_when_cost_is_fully_attributed(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('whatsapp', 0.50, true, 'pix', 30, 10.0, 0.0),
            $this->order('whatsapp', 0.50, false, 'pix', 30, 10.0, 0.0),
        ]);

        $channel = $groups[0]['channels'][0];

        self::assertSame(1.0, $channel['total_attempt_cost']);
        self::assertSame(10.0, $channel['recovered_platform_contribution']);
        self::assertSame(9.0, $channel['realized_net_contribution']);
        self::assertSame(4.5, $channel['realized_net_contribution_per_attempt']);
        self::assertSame(900.0, $channel['realized_roi_percent']);
    }

    public function test_realized_profit_is_unknown_when_cost_attribution_is_incomplete(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('whatsapp', 0.50, true, 'pix', 30, 10.0, 0.0),
            $this->order('whatsapp', null, false, 'pix', 30, 10.0, 0.0),
        ]);

        $channel = $groups[0]['channels'][0];

        self::assertSame(50.0, $channel['cost_coverage_percent']);
        self::assertNull($channel['realized_net_contribution']);
        self::assertNull($channel['realized_net_contribution_per_attempt']);
        self::assertNull($channel['realized_roi_percent']);
    }

    public function test_zero_cost_channel_exposes_realized_profit_without_fake_infinite_roi(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('in_app', 0.0, true, 'pix', 10, 7.0, 0.0),
            $this->order('in_app', 0.0, false, 'pix', 10, 7.0, 0.0),
        ]);

        $channel = $groups[0]['channels'][0];

        self::assertSame(7.0, $channel['realized_net_contribution']);
        self::assertSame(3.5, $channel['realized_net_contribution_per_attempt']);
        self::assertNull($channel['realized_roi_percent']);
    }

    public function test_it_does_not_recommend_a_channel_with_incomplete_cost_attribution(): void
    {
        $orders = [
            $this->order('whatsapp', 0.50, true, 'pix', 10, 10.0, 0.0),
            $this->order('whatsapp', null, true, 'pix', 10, 10.0, 0.0),
        ];

        $groups = (new ObservedRecoveryChannelEconomics())->summarize($orders);

        self::assertNull($groups[0]['recommended_channel']);
        self::assertSame(50.0, $groups[0]['channels'][0]['cost_coverage_percent']);
        self::assertNull($groups[0]['channels'][0]['attempt_cost']);
        self::assertFalse($groups[0]['channels'][0]['recommended']);
    }

    public function test_it_segments_by_payment_method_and_abandonment_age(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('email', 0.01, true, 'pix', 5, 5.0, 0.0),
            $this->order('email', 0.01, true, 'card', 90, 5.0, 0.0),
        ]);

        self::assertCount(2, $groups);
        self::assertSame(['card|1_6h', 'pix|0_15m'], array_map(
            static fn (array $group): string => $group['payment_method'].'|'.$group['abandonment_age_bucket'],
            $groups,
        ));
    }

    public function test_platform_collection_uses_processor_fee_in_real_contribution(): void
    {
        $groups = (new ObservedRecoveryChannelEconomics())->summarize([
            $this->order('email', 0.01, true, 'pix', 20, 5.0, 2.0, 'platform_collection'),
        ]);

        self::assertSame(3.0, $groups[0]['channels'][0]['recovered_platform_contribution']);
        self::assertSame(3.0, $groups[0]['channels'][0]['contribution_per_recovered_order']);
    }

    /** @return array<string, mixed> */
    private function order(
        string $channel,
        ?float $attemptCost,
        bool $paid,
        string $paymentMethod,
        int $ageMinutes,
        float $platformFee,
        float $processorFee,
        string $settlementMode = 'automatic_split',
    ): array {
        return [
            'payment_method' => $paymentMethod,
            'status' => $paid ? 'paid' : 'pending',
            'platform_fee' => $platformFee,
            'processor_fee' => $processorFee,
            'created_at' => '2026-09-08 10:00:00',
            'recovery_started_at' => date('Y-m-d H:i:s', strtotime('2026-09-08 10:00:00 +'.$ageMinutes.' minutes')),
            'metadata' => [
                'settlement_mode' => $settlementMode,
                'recovery' => [
                    'channel' => $channel,
                    'attempt_cost' => $attemptCost,
                ],
            ],
        ];
    }
}
