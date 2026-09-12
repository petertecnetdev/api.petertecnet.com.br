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
