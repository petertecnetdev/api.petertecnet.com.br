<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FinancialLedgerService
{
    /**
     * Post an immutable, balanced financial transaction.
     *
     * @param array<int, array{financial_account_id:int,direction:string,amount_cents:int,role:string}> $entries
     * @param array<string, mixed> $attributes
     */
    public function post(int $applicationId, string $idempotencyKey, array $entries, array $attributes = []): object
    {
        if ($applicationId < 1 || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('application_id and idempotency_key are required.');
        }

        $this->assertBalanced($entries);

        return DB::transaction(function () use ($applicationId, $idempotencyKey, $entries, $attributes) {
            $existing = DB::table('financial_transactions')
                ->where('application_id', $applicationId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertSameOperation($existing, $entries, $attributes);

                return $existing;
            }

            $accountIds = collect($entries)->pluck('financial_account_id')->unique()->values();
            $validAccounts = DB::table('financial_accounts')
                ->where('application_id', $applicationId)
                ->whereIn('id', $accountIds)
                ->where('status', 'active')
                ->lockForUpdate()
                ->pluck('id');

            if ($validAccounts->count() !== $accountIds->count()) {
                throw new InvalidArgumentException('Every ledger account must be active and belong to the same application.');
            }

            $now = now();
            $transactionId = DB::table('financial_transactions')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'application_id' => $applicationId,
                'type' => $attributes['type'] ?? 'payment',
                'status' => $attributes['status'] ?? 'posted',
                'currency' => $attributes['currency'] ?? 'BRL',
                'provider' => $attributes['provider'] ?? null,
                'provider_reference' => $attributes['provider_reference'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'metadata' => isset($attributes['metadata']) ? json_encode($attributes['metadata'], JSON_THROW_ON_ERROR) : null,
                'occurred_at' => $attributes['occurred_at'] ?? $now,
                'settled_at' => $attributes['settled_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($entries as $entry) {
                DB::table('financial_ledger_entries')->insert([
                    'application_id' => $applicationId,
                    'financial_transaction_id' => $transactionId,
                    'financial_account_id' => $entry['financial_account_id'],
                    'direction' => $entry['direction'],
                    'amount_cents' => $entry['amount_cents'],
                    'currency' => $attributes['currency'] ?? 'BRL',
                    'role' => $entry['role'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return DB::table('financial_transactions')->where('id', $transactionId)->first();
        }, 3);
    }

    /**
     * Idempotency means replaying the same operation, never accepting a different
     * financial operation under an already-consumed key.
     *
     * @param array<int, array{financial_account_id:int,direction:string,amount_cents:int,role:string}> $entries
     * @param array<string, mixed> $attributes
     */
    private function assertSameOperation(object $existing, array $entries, array $attributes): void
    {
        $expected = [
            'type' => $attributes['type'] ?? 'payment',
            'status' => $attributes['status'] ?? 'posted',
            'currency' => $attributes['currency'] ?? 'BRL',
            'provider' => $attributes['provider'] ?? null,
            'provider_reference' => $attributes['provider_reference'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => isset($attributes['source_id']) ? (int) $attributes['source_id'] : null,
        ];

        foreach ($expected as $field => $value) {
            $actual = $existing->{$field} ?? null;
            if ($field === 'source_id' && $actual !== null) {
                $actual = (int) $actual;
            }

            if ($actual !== $value) {
                throw new InvalidArgumentException('Idempotency key is already associated with a different financial operation.');
            }
        }

        $storedEntries = DB::table('financial_ledger_entries')
            ->where('financial_transaction_id', $existing->id)
            ->get(['financial_account_id', 'direction', 'amount_cents', 'role'])
            ->map(fn ($entry) => [
                'financial_account_id' => (int) $entry->financial_account_id,
                'direction' => (string) $entry->direction,
                'amount_cents' => (int) $entry->amount_cents,
                'role' => (string) $entry->role,
            ])
            ->sortBy(fn (array $entry) => implode('|', [$entry['financial_account_id'], $entry['direction'], $entry['amount_cents'], $entry['role']]))
            ->values()
            ->all();

        $requestedEntries = collect($entries)
            ->map(fn (array $entry) => [
                'financial_account_id' => (int) $entry['financial_account_id'],
                'direction' => (string) $entry['direction'],
                'amount_cents' => (int) $entry['amount_cents'],
                'role' => (string) $entry['role'],
            ])
            ->sortBy(fn (array $entry) => implode('|', [$entry['financial_account_id'], $entry['direction'], $entry['amount_cents'], $entry['role']]))
            ->values()
            ->all();

        if ($storedEntries !== $requestedEntries) {
            throw new InvalidArgumentException('Idempotency key is already associated with different ledger entries.');
        }
    }

    /** @param array<int, array{financial_account_id:int,direction:string,amount_cents:int,role:string}> $entries */
    private function assertBalanced(array $entries): void
    {
        if (count($entries) < 2) {
            throw new InvalidArgumentException('A ledger transaction requires at least two entries.');
        }

        $debits = 0;
        $credits = 0;

        foreach ($entries as $entry) {
            $direction = $entry['direction'] ?? null;
            $amount = (int) ($entry['amount_cents'] ?? 0);

            if (! in_array($direction, ['debit', 'credit'], true) || $amount <= 0 || empty($entry['financial_account_id']) || empty($entry['role'])) {
                throw new InvalidArgumentException('Ledger entries require account, role, positive amount and debit/credit direction.');
            }

            $direction === 'debit' ? $debits += $amount : $credits += $amount;
        }

        if ($debits !== $credits) {
            throw new InvalidArgumentException('Ledger transaction is not balanced.');
        }
    }
}
