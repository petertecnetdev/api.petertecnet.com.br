<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Data\PaymentRecordData;
use App\Models\EcosystemPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentRecordService
{
    public function __construct(private readonly PaymentProviderResolver $providers)
    {
    }

    public function persist(PaymentRecordData $data): EcosystemPayment
    {
        return DB::transaction(function () use ($data) {
            $payment = EcosystemPayment::query()
                ->where('app_slug', $data->applicationSlug)
                ->where('source_type', $data->sourceType)
                ->where('source_reference', $data->sourceReference)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $payment = new EcosystemPayment([
                    'public_id' => (string) Str::uuid(),
                    'app_id' => $data->applicationId,
                    'app_slug' => $data->applicationSlug,
                    'source_type' => $data->sourceType,
                    'source_reference' => $data->sourceReference,
                ]);
            }

            $provider = $this->providers->resolve($data->applicationSlug, $data->provider);
            $sellerNet = $data->sellerNet ?? max(
                0,
                $data->grossAmount - $data->platformFee - $data->providerFee
            );

            $payment->fill([
                'app_id' => $data->applicationId,
                'app_slug' => $data->applicationSlug,
                'provider' => $provider,
                'provider_payment_id' => $data->providerPaymentId,
                'source_type' => $data->sourceType,
                'source_reference' => $data->sourceReference,
                'source_id' => $data->sourceId,
                'user_id' => $data->userId,
                'production_id' => $data->productionId,
                'establishment_id' => $data->establishmentId,
                'currency' => $data->currency,
                'method' => $data->method,
                'status' => $data->status,
                'gross_amount' => $data->grossAmount,
                'platform_fee' => $data->platformFee,
                'provider_fee' => $data->providerFee,
                'seller_net' => $sellerNet,
                'metadata' => $data->metadata,
            ]);

            if ($data->status === 'paid' && ! $payment->paid_at) {
                $payment->paid_at = now();
            }
            if ($data->status === 'failed' && ! $payment->failed_at) {
                $payment->failed_at = now();
            }
            if ($data->status === 'refunded' && ! $payment->refunded_at) {
                $payment->refunded_at = now();
            }

            $payment->save();

            return $payment->fresh();
        }, 3);
    }
}
