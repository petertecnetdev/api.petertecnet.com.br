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

    public function test_it_breaks_explicit_abandonment_down_by_reason_time_method_and_cart_type(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-abandon-pix', 10, 90.00, 'pix'),
            $this->abandonment('journey-abandon-pix', 90.00, 'pix', 'page_hidden', 45000, 2, 1),
            $this->interaction('frontend_checkout_opened', 'journey-abandon-card', 10, 40.00, 'card'),
            $this->abandonment('journey-abandon-card', 40.00, 'card', 'navigation', 210000, 1, 0),
        ];

        $summary = $funnel->summarize($interactions, [10], 10.0, ['pix' => 8.0, 'card' => 5.0]);
        $diagnostics = $summary['dropoff']['abandonment_diagnostics'];

        $this->assertSame('page_hidden', $diagnostics['by_reason'][0]['key']);
        $this->assertSame(90.0, $diagnostics['by_reason'][0]['gmv_at_risk']);
        $this->assertSame(7.2, $diagnostics['by_reason'][0]['platform_contribution_at_risk']);
        $this->assertSame('pix', $diagnostics['by_payment_method'][0]['key']);
        $this->assertSame('30_to_59s', $diagnostics['by_elapsed_time'][0]['key']);
        $this->assertSame('tickets_plus_items', $diagnostics['by_cart_type'][0]['key']);
        $this->assertSame('tickets_only', $diagnostics['by_cart_type'][1]['key']);
    }

    public function test_it_prioritizes_the_step_with_the_largest_gmv_loss_not_only_the_most_journeys(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-big1', 10, 500.00),
            $this->interaction('frontend_payment_attempted', 'journey-big1', null, 500.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-small1', 10, 20.00),
            $this->interaction('frontend_payment_attempted', 'journey-small1', null, 20.00, 'pix'),
            $this->interaction('frontend_payment_approved', 'journey-small1', null, 20.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-small2', 10, 20.00),
            $this->interaction('frontend_checkout_opened', 'journey-small3', 10, 20.00),
        ];

        $summary = $funnel->summarize($interactions, [10]);

        $this->assertSame('checkout_opened', $summary['dropoff']['largest_step']['from']);
        $this->assertSame(2, $summary['dropoff']['largest_step']['dropoff_journeys']);
        $this->assertSame(40.0, $summary['dropoff']['largest_step']['gmv_at_risk']);
        $this->assertSame('payment_attempted', $summary['dropoff']['largest_economic_step']['from']);
        $this->assertSame(1, $summary['dropoff']['largest_economic_step']['dropoff_journeys']);
        $this->assertSame(500.0, $summary['dropoff']['largest_economic_step']['gmv_at_risk']);
    }

    public function test_it_prioritizes_observed_platform_contribution_instead_of_gmv_alone(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $interactions = [
            $this->interaction('frontend_checkout_opened', 'journey-card-big', 10, 500.00, 'card'),
            $this->interaction('frontend_payment_attempted', 'journey-card-big', null, 500.00, 'card'),
            $this->interaction('frontend_checkout_opened', 'journey-pix-one', 10, 60.00, 'pix'),
            $this->interaction('frontend_checkout_opened', 'journey-pix-two', 10, 50.00, 'pix'),
        ];
        $summary = $funnel->summarize($interactions, [10], 5.0, ['card' => 1.0, 'pix' => 10.0]);
        $this->assertSame('payment_attempted', $summary['dropoff']['largest_economic_step']['from']);
        $this->assertSame(500.0, $summary['dropoff']['largest_economic_step']['gmv_at_risk']);
        $this->assertSame('checkout_opened', $summary['dropoff']['largest_contribution_step']['from']);
        $this->assertSame(11.0, $summary['dropoff']['largest_contribution_step']['platform_contribution_at_risk']);
        $this->assertSame('reduce_payment_entry_friction', $summary['dropoff']['largest_contribution_step']['recommended_action']['code']);
        $this->assertSame('opened_to_attempted_percent', $summary['dropoff']['largest_contribution_step']['recommended_action']['target_metric']);
        $this->assertContains('cart_integrity', $summary['dropoff']['largest_contribution_step']['recommended_action']['guardrails']);
        $this->assertSame(10.0, $summary['platform_contribution_estimate']['payment_method_margin_percent']['pix']);
        $this->assertSame(1.0, $summary['platform_contribution_estimate']['payment_method_margin_percent']['card']);
    }

    public function test_it_recommends_safe_actions_for_payment_and_fulfillment_dropoffs(): void
    {
        $funnel = new CheckoutJourneyFunnel();
        $payment = $funnel->summarize([
            $this->interaction('frontend_checkout_opened', 'journey-payment', 10, 100.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-payment', null, 100.00, 'pix'),
        ], [10], 5.0, ['pix' => 5.0]);
        $paymentStep = $payment['dropoff']['steps'][1];
        $this->assertSame('improve_payment_approval', $paymentStep['recommended_action']['code']);
        $this->assertContains('payment_idempotency', $paymentStep['recommended_action']['guardrails']);

        $fulfillment = $funnel->summarize([
            $this->interaction('frontend_checkout_opened', 'journey-fulfillment', 10, 100.00, 'pix'),
            $this->interaction('frontend_payment_attempted', 'journey-fulfillment', null, 100.00, 'pix'),
            $this->interaction('frontend_payment_approved', 'journey-fulfillment', null, 100.00, 'pix'),
        ], [10], 5.0, ['pix' => 5.0]);
        $fulfillmentStep = $fulfillment['dropoff']['steps'][2];
        $this->assertSame('protect_post_payment_fulfillment', $fulfillmentStep['recommended_action']['code']);
        $this->assertContains('qr_checkin_integrity', $fulfillmentStep['recommended_action']['guardrails']);
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

    private function abandonment(string $journeyId, float $amount, string $paymentMethod, string $reason, int $elapsedMs, int $ticketQuantity, int $itemQuantity): array
    {
        return [
            'interaction_type' => 'frontend_checkout_abandoned',
            'content' => ['metadata' => [
                'checkout_journey_id' => $journeyId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reason' => $reason,
                'elapsed_ms' => $elapsedMs,
                'ticket_quantity' => $ticketQuantity,
                'item_quantity' => $itemQuantity,
            ]],
        ];
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
