<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\CheckoutJourneyFunnel;
use PHPUnit\Framework\TestCase;

class CheckoutJourneyFunnelTest extends TestCase
{
    public function test_it_correlates_checkout_stages_and_reports_dropoff_and_gmv(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-a', 10, 120.00),
            $this->interaction('frontend_checkout_mobile_payment_cta_clicked', 'journey-a', null, 120.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-a', null, 120.00, 'pix'),
            $this->interaction('frontend_payment_approved', 'journey-a', null, 120.00, 'pix'),
            $this->interaction('frontend_checkout_fulfilled', 'journey-a', null, 120.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-b', 10, 80.00),
            $this->interaction('frontend_checkout_abandoned', 'journey-b', null, 80.00, 'card'),
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame(2, $summary['journeys']);
        $this->assertSame(2, $summary['stages']['opened']);
        $this->assertSame(1, $summary['stages']['payment_attempted']);
        $this->assertSame(1, $summary['stages']['payment_approved']);
        $this->assertSame(50.0, $summary['conversion']['opened_to_approved_percent']);
        $this->assertSame(1, $summary['dropoff']['explicit_abandoned_journeys']);
        $this->assertSame('checkout_opened', $summary['dropoff']['largest_step']['from']);
        $this->assertSame('checkout_opened', $summary['dropoff']['largest_economic_step']['from']);
        $this->assertSame(80.0, $summary['dropoff']['largest_economic_step']['gmv_at_risk']);
        $this->assertSame(200.0, $summary['gmv']['opened']);
        $this->assertSame(120.0, $summary['gmv']['approved']);
        $this->assertSame(80.0, $summary['gmv']['explicit_abandoned_at_risk']);
        $this->assertSame(200.0, $summary['gmv']['by_stage']['opened']);
        $this->assertSame(120.0, $summary['gmv']['by_stage']['payment_attempted']);
    }

    public function test_it_prioritizes_the_step_with_the_largest_gmv_loss_not_only_the_most_journeys(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-big1', 10, 500.00),
            $this->interaction('frontend_payment_attempted', 'journey-big1', null, 500.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-small1', 10, 20.00),
            $this->interaction('frontend_checkout_opened', 'journey-small2', 10, 20.00),
            $this->interaction('frontend_checkout_opened', 'journey-small3', 10, 20.00),
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame(3, $summary['dropoff']['largest_step']['dropoff_journeys']);
        $this->assertSame('checkout_opened', $summary['dropoff']['largest_economic_step']['from']);
        $this->assertSame(60.0, $summary['dropoff']['largest_economic_step']['gmv_at_risk']);
    }

    public function test_it_excludes_journeys_outside_the_producer_event_scope(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-owned', 10, 50.00),
            $this->interaction('frontend_payment_attempted', 'journey-owned', null, 50.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-other', 99, 900.00),
            $this->interaction('frontend_payment_approved', 'journey-other', null, 900.00, 'pix'),
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame(1, $summary['journeys']);
        $this->assertSame(50.0, $summary['gmv']['opened']);
        $this->assertSame(0.0, $summary['gmv']['approved']);
    }

    public function test_it_ignores_missing_or_invalid_journey_identifiers(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'bad', 10, 100.00),
            ['interaction_type' => 'frontend_checkout_opened', 'content' => ['metadata' => ['event_id' => 10, 'amount' => 100]]],
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame(0, $summary['journeys']);
        $this->assertNull($summary['conversion']['opened_to_approved_percent']);
        $this->assertNull($summary['dropoff']['largest_economic_step']);
    }

    private function interaction(string $type, string $journeyId, ?int $eventId, float $amount, ?string $paymentMethod = null): array
    {
        $metadata = [
            'checkout_journey_id' => $journeyId,
            'amount' => $amount,
        ];
        if ($eventId !== null) {
            $metadata['event_id'] = $eventId;
        }
        if ($paymentMethod !== null) {
            $metadata['payment_method'] = $paymentMethod;
        }

        return ['interaction_type' => $type, 'content' => ['metadata' => $metadata]];
    }
}
