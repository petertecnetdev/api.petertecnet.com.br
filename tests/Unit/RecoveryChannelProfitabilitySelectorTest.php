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
    }
}
