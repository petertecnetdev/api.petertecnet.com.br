<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\RecoveryChannelProfitabilitySelector;
use PHPUnit\Framework\TestCase;

final class RecoveryChannelProfitabilitySelectorTest extends TestCase
{
    public function test_it_prioritizes_the_channel_with_highest_conservative_net_contribution(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'email',
                'attempt_cost' => 0.05,
                'conversion_rate' => 8.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
            ],
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.50,
                'conversion_rate' => 15.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
            ],
        ], 0.60);

        self::assertSame('whatsapp', $ranked[0]['channel']);
        self::assertTrue($ranked[0]['recommended']);
        self::assertTrue($ranked[0]['has_minimum_sample']);
        self::assertTrue($ranked[0]['has_safe_cost_ceiling']);
        self::assertGreaterThan($ranked[1]['expected_net_contribution_per_attempt'], $ranked[0]['expected_net_contribution_per_attempt']);
    }

    public function test_it_rejects_a_channel_above_the_safe_cost_ceiling(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.80,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 500,
            ],
            [
                'channel' => 'email',
                'attempt_cost' => 0.05,
                'conversion_rate' => 5.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 500,
            ],
        ], 0.60);

        $whatsapp = collect($ranked)->firstWhere('channel', 'whatsapp');
        self::assertFalse($whatsapp['within_safe_cost_ceiling']);
        self::assertFalse($whatsapp['recommended']);
        self::assertContains('above_safe_cost_ceiling', $whatsapp['recommendation_blockers']);
        self::assertSame('email', $ranked[0]['channel']);
    }

    public function test_it_does_not_invent_economics_when_cost_or_conversion_is_missing(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'push',
                'attempt_cost' => null,
                'conversion_rate' => null,
                'contribution_per_recovered_order' => 20.0,
            ],
        ], 0.50);

        self::assertNull($ranked[0]['expected_net_contribution_per_attempt']);
        self::assertNull($ranked[0]['economically_viable']);
        self::assertFalse($ranked[0]['recommended']);
        self::assertContains('incomplete_economics', $ranked[0]['recommendation_blockers']);
    }

    public function test_it_does_not_recommend_a_profitable_channel_before_minimum_sample(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.10,
                'conversion_rate' => 100.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 1,
            ],
        ], 1.00);

        self::assertTrue($ranked[0]['economically_viable']);
        self::assertFalse($ranked[0]['has_minimum_sample']);
        self::assertSame(RecoveryChannelProfitabilitySelector::DEFAULT_MIN_SAMPLE_ATTEMPTS, $ranked[0]['minimum_sample_attempts']);
        self::assertContains('insufficient_sample', $ranked[0]['recommendation_blockers']);
        self::assertFalse($ranked[0]['recommended']);
    }

    public function test_it_allows_an_explicit_higher_sample_threshold_for_sensitive_channels(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.10,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 50,
            ],
        ], 1.00, 100);

        self::assertFalse($ranked[0]['has_minimum_sample']);
        self::assertSame(100, $ranked[0]['minimum_sample_attempts']);
        self::assertFalse($ranked[0]['recommended']);
    }

    public function test_it_does_not_recommend_a_paid_channel_without_an_explicit_safe_cost_ceiling(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'whatsapp',
                'attempt_cost' => 0.10,
                'conversion_rate' => 20.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
            ],
        ]);

        self::assertTrue($ranked[0]['economically_viable']);
        self::assertFalse($ranked[0]['has_safe_cost_ceiling']);
        self::assertNull($ranked[0]['within_safe_cost_ceiling']);
        self::assertContains('missing_safe_cost_ceiling', $ranked[0]['recommendation_blockers']);
        self::assertFalse($ranked[0]['recommended']);
    }

    public function test_it_can_recommend_a_zero_cost_channel_without_an_external_cost_ceiling(): void
    {
        $ranked = (new RecoveryChannelProfitabilitySelector())->rank([
            [
                'channel' => 'in_app',
                'attempt_cost' => 0.0,
                'conversion_rate' => 10.0,
                'contribution_per_recovered_order' => 20.0,
                'attempts' => 200,
            ],
        ]);

        self::assertTrue($ranked[0]['has_safe_cost_ceiling']);
        self::assertTrue($ranked[0]['within_safe_cost_ceiling']);
        self::assertTrue($ranked[0]['economically_viable']);
        self::assertNotContains('missing_safe_cost_ceiling', $ranked[0]['recommendation_blockers']);
        self::assertTrue($ranked[0]['recommended']);
    }
}
