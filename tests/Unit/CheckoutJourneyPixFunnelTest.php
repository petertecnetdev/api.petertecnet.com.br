<?php

namespace Tests\Unit;

use App\Domain\Analytics\Services\CheckoutJourneyFunnel;
use PHPUnit\Framework\TestCase;

class CheckoutJourneyPixFunnelTest extends TestCase
{
    public function test_it_measures_pix_ready_copy_optional_status_check_and_approval_without_polluting_card(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-pix-paid', 10, 120.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-pix-paid', null, 120.00, 'pix'),
            $this->interaction('frontend_pix_payment_ready', 'journey-pix-paid', null, 120.00, 'pix'),
            $this->interaction('frontend_pix_code_copied', 'journey-pix-paid', null, 120.00, 'pix'),
            $this->interaction('frontend_pix_post_copy_status_check_clicked', 'journey-pix-paid', null, 120.00, 'pix'),
            $this->interaction('frontend_payment_approved', 'journey-pix-paid', null, 120.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-pix-copy-drop', 10, 80.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-pix-copy-drop', null, 80.00, 'pix'),
            $this->interaction('frontend_pix_payment_ready', 'journey-pix-copy-drop', null, 80.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-card-paid', 10, 300.00, 'card'),
            $this->interaction('frontend_payment_attempted', 'journey-card-paid', null, 300.00, 'card'),
            $this->interaction('frontend_payment_approved', 'journey-card-paid', null, 300.00, 'card'),
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame(2, $summary['pix']['journeys']);
        $this->assertSame(2, $summary['pix']['ready']);
        $this->assertSame(1, $summary['pix']['copied']);
        $this->assertSame(1, $summary['pix']['post_copy_check']);
        $this->assertSame(1, $summary['pix']['approved']);
        $this->assertSame(50.0, $summary['pix']['ready_to_copied_percent']);
        $this->assertSame(100.0, $summary['pix']['copied_to_post_copy_check_percent']);
        $this->assertSame(100.0, $summary['pix']['copied_to_approved_percent']);
        $this->assertSame(50.0, $summary['pix']['ready_to_approved_percent']);
        $this->assertSame(200.0, $summary['pix']['gmv_ready']);
        $this->assertSame(120.0, $summary['pix']['gmv_copied']);
        $this->assertSame(120.0, $summary['pix']['gmv_approved']);
        $this->assertSame('pix_payment_ready', $summary['pix']['largest_economic_step']['from']);
        $this->assertSame(80.0, $summary['pix']['largest_economic_step']['gmv_at_risk']);
        $this->assertSame('improve_pix_copy_completion', $summary['pix']['largest_economic_step']['recommended_action']['code']);
        $this->assertSame(2, $summary['stages']['payment_approved']);
    }

    private function interaction(string $type, string $journeyId, ?int $eventId, ?float $amount, ?string $paymentMethod = null): array
    {
        $metadata = ['checkout_journey_id' => $journeyId];
        if ($eventId !== null) {
            $metadata['event_id'] = $eventId;
        }
        if ($amount !== null) {
            $metadata['amount'] = $amount;
        }
        if ($paymentMethod !== null) {
            $metadata['payment_method'] = $paymentMethod;
        }

        return ['interaction_type' => $type, 'content' => ['metadata' => $metadata]];
    }
}
