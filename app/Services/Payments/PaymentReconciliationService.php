<?php

namespace App\Services\Payments;

use App\Models\EcosystemPayment;
use App\Models\PaymentReconciliation;
use Illuminate\Support\Facades\Schema;

class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentStateSynchronizer $synchronizer,
    ) {}

    public function reconcileBatch(int $limit = 100): array
    {
        if (! Schema::hasTable('ecosystem_payments') || ! Schema::hasTable('payment_reconciliations')) {
            return ['checked' => 0, 'matched' => 0, 'corrected' => 0, 'errors' => 0];
        }

        $candidates = EcosystemPayment::query()
            ->whereNotNull('provider')
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'in_process', 'authorized'])
                    ->orWhereNull('reconciled_at')
                    ->orWhere('reconciled_at', '<=', now()->subHours(6));
            })
            ->orderByRaw("CASE WHEN status IN ('pending','in_process','authorized') THEN 0 ELSE 1 END")
            ->orderBy('reconciled_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $stats = ['checked' => 0, 'matched' => 0, 'corrected' => 0, 'errors' => 0];
        foreach ($candidates as $payment) {
            $result = $this->reconcile($payment);
            $stats['checked']++;
            $stats[$result]++;
        }

        return $stats;
    }

    public function reconcile(EcosystemPayment $payment): string
    {
        $localStatus = $this->normalizeStatus((string) $payment->status);
        $localAmount = (float) $payment->gross_amount;
        $localFee = (float) $payment->provider_fee;

        try {
            $gateway = $this->gateways->for((string) $payment->provider);
            if (! $gateway->isConfigured()) {
                return $this->recordError($payment, $localStatus, 'provider_not_configured', 'Provedor não configurado para conciliação.');
            }

            $remote = $gateway->retrieve($payment->provider_payment_id, $payment->source_reference);
            if (! $remote) {
                return $this->recordError($payment, $localStatus, 'remote_not_found', 'Pagamento não localizado no provedor.');
            }

            $remoteStatus = $this->normalizeStatus($remote->status);
            $statusMatches = $localStatus === $remoteStatus;
            $amountMatches = $remote->grossAmount === null || abs($localAmount - $remote->grossAmount) <= 0.01;
            $feeMatches = abs($localFee - $remote->providerFee) <= 0.01;
            $matched = $statusMatches && $amountMatches && $feeMatches;

            $codes = [];
            if (! $statusMatches) $codes[] = 'status';
            if (! $amountMatches) $codes[] = 'amount';
            if (! $feeMatches) $codes[] = 'provider_fee';
            $discrepancy = $codes ? implode('_', $codes) . '_mismatch' : null;

            PaymentReconciliation::query()->create([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_payment_id' => $remote->providerPaymentId ?: $payment->provider_payment_id,
                'local_status' => $localStatus,
                'remote_status' => $remoteStatus,
                'local_amount' => $localAmount,
                'remote_amount' => $remote->grossAmount,
                'local_provider_fee' => $localFee,
                'remote_provider_fee' => $remote->providerFee,
                'matched' => $matched,
                'discrepancy_code' => $discrepancy,
                'details' => [
                    'provider_status' => $remote->providerStatus,
                    'available_at' => $remote->availableAt,
                    'expires_at' => $remote->expiresAt,
                ],
                'checked_at' => now(),
            ]);

            if (! $matched) {
                $this->synchronizer->apply($payment, $remote);
                $payment->refresh()->forceFill([
                    'reconciled_at' => now(),
                    'reconciliation_status' => 'corrected',
                    'reconciliation_message' => 'Divergência detectada e projeção local sincronizada com o provedor: ' . $discrepancy,
                ])->saveQuietly();

                return 'corrected';
            }

            $payment->forceFill([
                'reconciled_at' => now(),
                'reconciliation_status' => 'matched',
                'reconciliation_message' => null,
                'available_at' => $payment->available_at ?: $remote->availableAt,
                'expires_at' => $payment->expires_at ?: $remote->expiresAt,
            ])->saveQuietly();

            return 'matched';
        } catch (\Throwable $exception) {
            report($exception);
            return $this->recordError($payment, $localStatus, 'provider_error', $exception->getMessage());
        }
    }

    public function snapshot(): array
    {
        if (! Schema::hasTable('payment_reconciliations')) {
            return ['last_check_at' => null, 'mismatches_24h' => 0, 'matched_24h' => 0, 'unverified_payments' => 0, 'errors_24h' => 0];
        }

        $since = now()->subDay();
        $recent = PaymentReconciliation::query()->where('checked_at', '>=', $since)->get();

        return [
            'last_check_at' => optional(PaymentReconciliation::query()->latest('checked_at')->first())->checked_at?->toIso8601String(),
            'mismatches_24h' => $recent->where('matched', false)->whereNotIn('discrepancy_code', ['provider_error', 'provider_not_configured'])->count(),
            'matched_24h' => $recent->where('matched', true)->count(),
            'errors_24h' => $recent->whereIn('discrepancy_code', ['provider_error', 'provider_not_configured'])->count(),
            'unverified_payments' => EcosystemPayment::query()->whereIn('reconciliation_status', ['unverified', 'error'])->count(),
        ];
    }

    private function recordError(EcosystemPayment $payment, string $localStatus, string $code, string $message): string
    {
        PaymentReconciliation::query()->create([
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'local_status' => $localStatus,
            'remote_status' => null,
            'local_amount' => (float) $payment->gross_amount,
            'remote_amount' => null,
            'local_provider_fee' => (float) $payment->provider_fee,
            'remote_provider_fee' => null,
            'matched' => false,
            'discrepancy_code' => $code,
            'details' => ['message' => mb_substr($message, 0, 1000)],
            'checked_at' => now(),
        ]);

        $payment->forceFill([
            'reconciled_at' => now(),
            'reconciliation_status' => 'error',
            'reconciliation_message' => mb_substr($message, 0, 500),
        ])->saveQuietly();

        return 'errors';
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved' => 'paid',
            'failed' => 'rejected',
            default => strtolower(trim($status)),
        };
    }
}
