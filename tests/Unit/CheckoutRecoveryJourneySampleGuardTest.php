<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\CheckoutRecoveryJourneyEconomics;
use PHPUnit\Framework\TestCase;

class CheckoutRecoveryJourneySampleGuardTest extends TestCase
{
    public function test_it_withholds_weakest_step_until_both_cohorts_have_comparable_volume(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $summary = $service->summarize([
            $this->interaction('frontend_checkout_opened', 'recovered-0001', true),
            $this->interaction('frontend_payment_attempted', 'recovered-0001', true),
            $this->interaction('frontend_checkout_opened', 'standard-0001', false),
            $this->interaction('frontend_payment_attempted', 'standard-0001', false),
        ], [10]);

        $this->assertFalse($summary['comparison']['sample']['is_comparable']);
        $this->assertSame(19, $summary['comparison']['sample']['recovered_remaining_to_comparable']);
        $this->assertSame(19, $summary['comparison']['sample']['standard_remaining_to_comparable']);
        $this->assertNull($summary['comparison']['weakest_recovered_step']);
    }

    public function test_it_identifies_the_weakest_step_only_after_sample_is_comparable(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $interactions = [];

        for ($i = 1; $i <= 20; $i++) {
            $recoveredId = sprintf('recovered-%04d', $i);
            $standardId = sprintf('standard-%04d', $i);

            $interactions[] = $this->interaction('frontend_checkout_opened', $recoveredId, true);
            if ($i <= 10) {
                $interactions[] = $this->interaction('frontend_payment_attempted', $recoveredId, true);
            }

            $interactions[] = $this->interaction('frontend_checkout_opened', $standardId, false);
            $interactions[] = $this->interaction('frontend_payment_attempted', $standardId, false);
        }

        $summary = $service->summarize($interactions, [10]);

        $this->assertTrue($summary['comparison']['sample']['is_comparable']);
        $this->assertSame(0, $summary['comparison']['sample']['recovered_remaining_to_comparable']);
        $this->assertSame(0, $summary['comparison']['sample']['standard_remaining_to_comparable']);
        $this->assertSame('opened_to_attempted', $summary['comparison']['weakest_recovered_step']['step']);
        $this->assertSame(-50.0, $summary['comparison']['weakest_recovered_step']['delta_percentage_points']);
        $this->assertSame('underperforming', $summary['comparison']['weakest_recovered_step']['status']);
    }

    private function interaction(string $type, string $journeyId, bool $recovered): array
    {
        $metadata = [
            'checkout_journey_id' => $journeyId,
            'event_id' => 10,
            'amount' => 100,
            'payment_method' => 'pix',
        ];

        if ($recovered) {
            $metadata['attribution_source'] = 'checkout_recovery';
        }

        return [
            'interaction_type' => $type,
            'content' => ['metadata' => $metadata],
        ];
    }
}
