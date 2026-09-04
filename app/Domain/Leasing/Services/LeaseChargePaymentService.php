<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Finance\Data\PaymentRecordData;
use App\Domain\Finance\Services\PaymentRecordService;
use App\Models\EcosystemPayment;
use Illuminate\Support\Facades\DB;

final class LeaseChargePaymentService
{
    public function __construct(private readonly PaymentRecordService $payments)
    {
    }

    public function prepare(
        int $applicationId,
        string $applicationSlug,
        object $lease,
        object $charge,
        int $actorUserId,
        string $method
    ): array {
        abort_if($charge->status === 'paid', 422, 'Esta cobrança já está paga.');

        return DB::transaction(function () use (
            $applicationId,
            $applicationSlug,
            $lease,
            $charge,
            $actorUserId,
            $method
        ) {
            $payment = $this->payments->persist(new PaymentRecordData(
                applicationId: $applicationId,
                applicationSlug: $applicationSlug,
                sourceType: 'lease_charge',
                sourceReference: (string) $charge->public_id,
                sourceId: (int) $charge->id,
                userId: (int) ($lease->tenant_user_id ?: $actorUserId),
                currency: 'BRL',
                method: $method,
                status: 'pending',
                grossAmount: (float) $charge->amount,
                metadata: [
                    'lease_id' => (int) $lease->id,
                    'charge_id' => (int) $charge->id,
                ],
            ));

            DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->where('id', $charge->id)
                ->update([
                    'ecosystem_payment_id' => $payment->id,
                    'payment_method' => $method,
                    'provider' => $payment->provider,
                    'status' => 'processing',
                    'updated_at' => now(),
                ]);

            return [
                'payment' => $payment,
                'charge' => DB::table('lease_charges')->where('app_id', $applicationId)->find($charge->id),
                'provider_checkout_required' => $payment->provider !== 'manual',
            ];
        }, 3);
    }

    public function markPaid(
        int $applicationId,
        string $applicationSlug,
        object $charge,
        int $actorUserId,
        string $method
    ): object {
        return DB::transaction(function () use (
            $applicationId,
            $applicationSlug,
            $charge,
            $actorUserId,
            $method
        ) {
            DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->where('id', $charge->id)
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => $method,
                    'updated_at' => now(),
                ]);

            $payment = $this->payments->persist(new PaymentRecordData(
                applicationId: $applicationId,
                applicationSlug: $applicationSlug,
                sourceType: 'lease_charge',
                sourceReference: (string) $charge->public_id,
                sourceId: (int) $charge->id,
                userId: $actorUserId,
                currency: 'BRL',
                method: $method,
                status: 'paid',
                grossAmount: (float) $charge->amount,
                provider: $charge->provider ?: 'manual',
                providerPaymentId: $charge->provider_payment_id ?: null,
                metadata: [
                    'charge_id' => (int) $charge->id,
                    'manual_confirmation' => true,
                ],
            ));

            DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->where('id', $charge->id)
                ->update([
                    'ecosystem_payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'updated_at' => now(),
                ]);

            return DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->find($charge->id);
        }, 3);
    }
}
