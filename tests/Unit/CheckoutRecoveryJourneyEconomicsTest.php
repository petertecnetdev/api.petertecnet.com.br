<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\CheckoutRecoveryJourneyEconomics;
use PHPUnit\Framework\TestCase;

class CheckoutRecoveryJourneyEconomicsTest extends TestCase
{
    public function test_it_measures_recovered_checkout_conversion_gmv_and_sources(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-recovered', 10, 150.00, 'card', [
                'attribution_source' => 'checkout_recovery',
                'attribution_recovery_source' => 'navbar',
            ]),
            $this->interaction('frontend_payment_attempted', 'journey-recovered', null, 150.00, 'card', [
                'attribution_source' => 'checkout_recovery',
                'attribution_recovery_source' => 'navbar',
            ]),
            $this->interaction('frontend_payment_approved', 'journey-recovered', null, 150.00, 'card', [
                'attribution_source' => 'checkout_recovery',
                'attribution_recovery_source' => 'navbar',
            ]),
            $this->interaction('frontend_checkout_fulfilled', 'journey-recovered', null, 150.00, 'card', [
                'attribution_source' => 'checkout_recovery',
                'attribution_recovery_source' => 'navbar',
            ]),
            $this->interaction('frontend_checkout_opened', 'journey-fresh', 10, 90.00, 'pix'),
        ];

        $summary = $service->summarize($interactions, [10]);

        $this->assertSame(1, $summary['journeys']);
        $this->assertSame(1, $summary['opened']);
        $this->assertSame(1, $summary['attempted']);
        $this->assertSame(1, $summary['approved']);
        $this->assertSame(1, $summary['fulfilled']);
        $this->assertSame(100.0, $summary['conversion']['opened_to_attempted_percent']);
        $this->assertSame(100.0, $summary['conversion']['attempted_to_approved_percent']);
        $this->assertSame(100.0, $summary['conversion']['approved_to_fulfilled_percent']);
        $this->assertSame(150.0, $summary['gmv']['opened']);
        $this->assertSame(150.0, $summary['gmv']['approved']);
        $this->assertSame(150.0, $summary['gmv']['fulfilled']);
        $this->assertSame('navbar', $summary['by_recovery_source'][0]['recovery_source']);
        $this->assertSame('card', $summary['by_payment_method'][0]['payment_method']);
    }

    public function test_it_uses_checkout_recovered_marker_for_local_resume_and_respects_event_scope(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $interactions = [
            $this->interaction('frontend_checkout_recovered', 'journey-local', null, 0.0),
            $this->interaction('frontend_checkout_opened', 'journey-local', 10, 80.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-local', null, 80.00, 'pix'),
            $this->interaction('frontend_checkout_recovered', 'journey-other', null, 0.0),
            $this->interaction('frontend_checkout_opened', 'journey-other', 99, 900.00, 'pix'),
            $this->interaction('frontend_payment_approved', 'journey-other', null, 900.00, 'pix'),
        ];

        $summary = $service->summarize($interactions, [10]);

        $this->assertSame(1, $summary['journeys']);
        $this->assertSame(1, $summary['attempted']);
        $this->assertSame(0, $summary['approved']);
        $this->assertSame(80.0, $summary['gmv']['opened']);
        $this->assertSame('local_resume', $summary['by_recovery_source'][0]['recovery_source']);
    }

    public function test_it_compares_recovered_and_standard_cohorts_without_claiming_causal_lift(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-recovered', 10, 100.00, 'pix', [
                'attribution_source' => 'checkout_recovery',
            ]),
            $this->interaction('frontend_payment_attempted', 'journey-recovered', null, 100.00, 'pix', [
                'attribution_source' => 'checkout_recovery',
            ]),
            $this->interaction('frontend_payment_approved', 'journey-recovered', null, 100.00, 'pix', [
                'attribution_source' => 'checkout_recovery',
            ]),
            $this->interaction('frontend_checkout_fulfilled', 'journey-recovered', null, 100.00, 'pix', [
                'attribution_source' => 'checkout_recovery',
            ]),
            $this->interaction('frontend_checkout_opened', 'journey-standard-a', 10, 200.00, 'card'),
            $this->interaction('frontend_payment_attempted', 'journey-standard-a', null, 200.00, 'card'),
            $this->interaction('frontend_payment_approved', 'journey-standard-a', null, 200.00, 'card'),
            $this->interaction('frontend_checkout_fulfilled', 'journey-standard-a', null, 200.00, 'card'),
            $this->interaction('frontend_checkout_opened', 'journey-standard-b', 10, 300.00, 'card'),
            $this->interaction('frontend_payment_attempted', 'journey-standard-b', null, 300.00, 'card'),
        ];

        $summary = $service->summarize($interactions, [10]);
        $standard = $summary['comparison']['standard'];

        $this->assertSame(2, $standard['journeys']);
        $this->assertSame(2, $standard['opened']);
        $this->assertSame(2, $standard['attempted']);
        $this->assertSame(1, $standard['approved']);
        $this->assertSame(1, $standard['fulfilled']);
        $this->assertSame(100.0, $standard['conversion']['opened_to_attempted_percent']);
        $this->assertSame(50.0, $standard['conversion']['attempted_to_approved_percent']);
        $this->assertSame(100.0, $standard['conversion']['approved_to_fulfilled_percent']);
        $this->assertSame(500.0, $standard['gmv']['opened']);
        $this->assertSame(200.0, $standard['gmv']['approved']);
        $this->assertSame(200.0, $standard['gmv']['fulfilled']);
        $this->assertSame(0.0, $summary['comparison']['delta_percentage_points']['opened_to_attempted']);
        $this->assertSame(50.0, $summary['comparison']['delta_percentage_points']['attempted_to_approved']);
        $this->assertSame(0.0, $summary['comparison']['delta_percentage_points']['approved_to_fulfilled']);
        $this->assertSame(33.33, $summary['comparison']['recovered_fulfilled_gmv_share_percent']);
        $this->assertSame(
            'descriptive_cohort_comparison_not_causal_incremental_lift',
            $summary['comparison']['interpretation'],
        );
    }

    public function test_it_ignores_invalid_journey_ids(): void
    {
        $service = new CheckoutRecoveryJourneyEconomics();
        $summary = $service->summarize([
            $this->interaction('frontend_checkout_opened', 'bad', 10, 100.00, 'pix', [
                'attribution_source' => 'checkout_recovery',
            ]),
        ], [10]);

        $this->assertSame(0, $summary['journeys']);
        $this->assertNull($summary['conversion']['opened_to_attempted_percent']);
    }

    /** @param array<string, mixed> $extraMetadata */
    private function interaction(
        string $type,
        string $journeyId,
        ?int $eventId,
        float $amount,
        ?string $paymentMethod = null,
        array $extraMetadata = [],
    ): array {
        $metadata = [
            'checkout_journey_id' => $journeyId,
            'amount' => $amount,
            ...$extraMetadata,
        ];
        if ($eventId !== null) {
            $metadata['event_id'] = $eventId;
        }
        if ($paymentMethod !== null) {
            $metadata['payment_method'] = $paymentMethod;
        }

        return [
            'interaction_type' => $type,
            'content' => ['metadata' => $metadata],
        ];
    }
}
