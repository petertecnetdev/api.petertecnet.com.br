<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PayoutObligationService
{
    /**
     * Persist the amount owed to a beneficiary without depending on a payout destination.
     * Replays for the same financial transaction and beneficiary are idempotent.
     *
     * @param array<string, mixed> $metadata
     */
    public function create(
        int $applicationId,
        int $sourceFinancialTransactionId,
        int $financialAccountId,
        string $beneficiaryType,
        int $beneficiaryId,
        int $amountCents,
        bool $hasPayoutDestination,
        array $metadata = []
    ): object {
        if ($applicationId < 1 || $sourceFinancialTransactionId < 1 || $financialAccountId < 1 || $beneficiaryId < 1 || $amountCents < 1 || trim($beneficiaryType) === '') {
            throw new InvalidArgumentException('Application, source transaction, account, beneficiary and positive amount are required.');
        }

        return DB::transaction(function () use ($applicationId, $sourceFinancialTransactionId, $financialAccountId, $beneficiaryType, $beneficiaryId, $amountCents, $hasPayoutDestination, $metadata) {
            $transaction = DB::table('financial_transactions')
                ->where('id', $sourceFinancialTransactionId)
                ->where('application_id', $applicationId)
                ->lockForUpdate()
                ->first();

            $account = DB::table('financial_accounts')
                ->where('id', $financialAccountId)
                ->where('application_id', $applicationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $transaction || ! $account) {
                throw new InvalidArgumentException('Source transaction and receivable account must belong to the same application.');
            }

            $existing = DB::table('payout_obligations')
                ->where('application_id', $applicationId)
                ->where('source_financial_transaction_id', $sourceFinancialTransactionId)
                ->where('beneficiary_type', $beneficiaryType)
                ->where('beneficiary_id', $beneficiaryId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->financial_account_id !== $financialAccountId || (int) $existing->amount_cents !== $amountCents || $existing->currency !== $transaction->currency) {
                    throw new InvalidArgumentException('Payout obligation replay conflicts with the original financial operation.');
                }

                return $existing;
            }

            $now = now();
            $id = DB::table('payout_obligations')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'application_id' => $applicationId,
                'financial_account_id' => $financialAccountId,
                'source_financial_transaction_id' => $sourceFinancialTransactionId,
                'beneficiary_type' => $beneficiaryType,
                'beneficiary_id' => $beneficiaryId,
                'amount_cents' => $amountCents,
                'currency' => $transaction->currency,
                'status' => $hasPayoutDestination ? 'eligible' : 'held',
                'hold_reason' => $hasPayoutDestination ? null : 'payout_destination_missing',
                'eligible_at' => $hasPayoutDestination ? $now : null,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('payout_obligations')->where('id', $id)->first();
        }, 3);
    }

    public function releaseHeld(int $applicationId, int $obligationId): object
    {
        return DB::transaction(function () use ($applicationId, $obligationId) {
            $obligation = DB::table('payout_obligations')
                ->where('id', $obligationId)
                ->where('application_id', $applicationId)
                ->lockForUpdate()
                ->first();

            if (! $obligation) {
                throw new InvalidArgumentException('Payout obligation does not belong to the application.');
            }

            if ($obligation->status === 'held' && $obligation->hold_reason === 'payout_destination_missing') {
                DB::table('payout_obligations')->where('id', $obligationId)->update([
                    'status' => 'eligible',
                    'hold_reason' => null,
                    'eligible_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return DB::table('payout_obligations')->where('id', $obligationId)->first();
        }, 3);
    }
}
