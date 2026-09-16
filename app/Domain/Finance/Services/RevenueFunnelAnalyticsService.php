<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SubscriptionIntent;
use Carbon\CarbonInterface;

class RevenueFunnelAnalyticsService
{
    /**
     * Return a measurable subscription revenue funnel for one application.
     *
     * Keeping the application filter mandatory prevents cross-product leakage and
     * makes the same analytics contract reusable by every Peter Tecnet product.
     */
    public function summary(string $application, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $application = strtolower(trim($application));

        if ($application === '') {
            throw new \InvalidArgumentException('Application is required.');
        }

        $base = SubscriptionIntent::query()->where('application', $application);

        if ($from) {
            $base->where('created_at', '>=', $from);
        }

        if ($to) {
            $base->where('created_at', '<=', $to);
        }

        $total = (clone $base)->count();
        $checkoutStarted = (clone $base)->whereNotNull('checkout_started_at')->count();
        $paymentPending = (clone $base)->whereNotNull('payment_pending_at')->count();
        $paid = (clone $base)->whereNotNull('paid_at')->count();
        $activated = (clone $base)->whereNotNull('activated_at')->count();
        $abandoned = (clone $base)->whereNotNull('abandoned_at')->count();
        $paidRevenueCents = (int) (clone $base)->whereNotNull('paid_at')->sum('price_cents');

        return [
            'application' => $application,
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            'counts' => [
                'intents' => $total,
                'checkout_started' => $checkoutStarted,
                'payment_pending' => $paymentPending,
                'paid' => $paid,
                'activated' => $activated,
                'abandoned' => $abandoned,
            ],
            'conversion' => [
                'intent_to_checkout' => $this->rate($checkoutStarted, $total),
                'checkout_to_paid' => $this->rate($paid, $checkoutStarted),
                'paid_to_activated' => $this->rate($activated, $paid),
                'intent_to_paid' => $this->rate($paid, $total),
            ],
            'revenue' => [
                'paid_cents' => $paidRevenueCents,
                'average_paid_cents' => $paid > 0 ? (int) round($paidRevenueCents / $paid) : 0,
            ],
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }
}
