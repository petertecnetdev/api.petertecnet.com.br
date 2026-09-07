<?php

namespace App\Domain\Acquisition\Services;

use App\Models\CommerceOrder;
use App\Support\ApplicationContext;
use Illuminate\Validation\ValidationException;

final class AcquisitionCommissionPolicy
{
    private const OBSERVATION_DAYS = 90;
    private const DEFAULT_MINIMUM_RETAINED_MARGIN_PERCENTAGE = 0.0;

    public function __construct(private readonly ApplicationContext $context) {}

    /** @return array<string, float|int> */
    public function economics(): array
    {
        $platformFeePercentage = round(max(0, min((float) $this->context->option('commerce.platform_fee_percent', 0), 100)), 2);
        $minimumRetainedMarginPercentage = round(max(0, min(
            (float) $this->context->option(
                'commerce.acquisition_min_platform_margin_percent',
                self::DEFAULT_MINIMUM_RETAINED_MARGIN_PERCENTAGE,
            ),
            $platformFeePercentage,
        )), 2);

        $orders = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('status', 'paid')
            ->where('created_at', '>=', now()->subDays(self::OBSERVATION_DAYS))
            ->get(['total', 'processor_fee', 'metadata']);

        $platformCollectionGross = 0.0;
        $platformCollectionProcessorFees = 0.0;

        foreach ($orders as $order) {
            if ((string) data_get($order->metadata, 'settlement_mode', 'unknown') !== 'platform_collection') {
                continue;
            }

            $platformCollectionGross += max(0, (float) $order->total);
            $platformCollectionProcessorFees += max(0, (float) $order->processor_fee);
        }

        $observedPlatformProcessingRate = $platformCollectionGross > 0
            ? round(($platformCollectionProcessorFees / $platformCollectionGross) * 100, 2)
            : 0.0;
        $processingReservePercentage = round(min($platformFeePercentage, max(0, $observedPlatformProcessingRate)), 2);
        $maximumCommissionPercentage = round(max(
            0,
            $platformFeePercentage - $processingReservePercentage - $minimumRetainedMarginPercentage,
        ), 2);

        return [
            'platform_fee_percentage' => $platformFeePercentage,
            'observed_platform_processing_rate' => $observedPlatformProcessingRate,
            'processing_reserve_percentage' => $processingReservePercentage,
            'minimum_retained_margin_percentage' => $minimumRetainedMarginPercentage,
            'max_commission_percentage' => $maximumCommissionPercentage,
            'observation_days' => self::OBSERVATION_DAYS,
        ];
    }

    public function maxPercentage(): float
    {
        return (float) $this->economics()['max_commission_percentage'];
    }

    public function assertPercentageAllowed(float $percentage, string $field = 'percentage'): void
    {
        $economics = $this->economics();
        $maximum = (float) $economics['max_commission_percentage'];

        if ($percentage <= $maximum) {
            return;
        }

        throw ValidationException::withMessages([
            $field => sprintf(
                'A comissão sobre o GMV não pode superar %.2f%%. O limite preserva %.2f%% de margem mínima da plataforma e %.2f%% de processamento observado quando a Peter Tecnet coleta o pagamento.',
                $maximum,
                (float) $economics['minimum_retained_margin_percentage'],
                (float) $economics['processing_reserve_percentage'],
            ),
        ]);
    }
}
