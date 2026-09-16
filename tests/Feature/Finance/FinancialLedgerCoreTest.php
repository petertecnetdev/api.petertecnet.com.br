<?php

namespace Tests\Feature\Finance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialLedgerCoreTest extends TestCase
{
    use RefreshDatabase;

    private function application(string $name): int
    {
        return DB::table('applications')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(int $appId, string $key, string $providerReference): int
    {
        return DB::table('financial_transactions')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'application_id' => $appId,
            'type' => 'payment',
            'status' => 'pending',
            'currency' => 'BRL',
            'provider' => 'mercado_pago',
            'provider_reference' => $providerReference,
            'idempotency_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_transaction_idempotency_is_enforced_inside_each_application(): void
    {
        $appA = $this->application('Finance App A');
        $appB = $this->application('Finance App B');

        $this->transaction($appA, 'checkout:123', 'mp-a-1');

        try {
            $this->transaction($appA, 'checkout:123', 'mp-a-2');
            $this->fail('Duplicate idempotency key was accepted inside the same application.');
        } catch (QueryException) {
            $this->assertDatabaseCount('financial_transactions', 1);
        }

        $this->transaction($appB, 'checkout:123', 'mp-b-1');

        $this->assertDatabaseHas('financial_transactions', [
            'application_id' => $appA,
            'idempotency_key' => 'checkout:123',
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'application_id' => $appB,
            'idempotency_key' => 'checkout:123',
        ]);
    }

    public function test_provider_reference_cannot_be_credited_twice_in_the_same_application(): void
    {
        $appId = $this->application('Provider Reference App');
        $this->transaction($appId, 'checkout:1', 'mp-payment-999');

        $this->expectException(QueryException::class);
        $this->transaction($appId, 'checkout:2', 'mp-payment-999');
    }

    public function test_webhook_event_is_idempotent_per_application_and_provider(): void
    {
        $appA = $this->application('Webhook App A');
        $appB = $this->application('Webhook App B');

        $receipt = [
            'provider' => 'mercado_pago',
            'event_id' => 'payment.updated:999',
            'event_type' => 'payment.updated',
            'payload_hash' => hash('sha256', '{"id":999}'),
            'payload' => json_encode(['id' => 999]),
            'status' => 'received',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payment_webhook_receipts')->insert($receipt + ['application_id' => $appA]);

        try {
            DB::table('payment_webhook_receipts')->insert($receipt + ['application_id' => $appA]);
            $this->fail('Duplicate webhook event was accepted for the same application/provider.');
        } catch (QueryException) {
            $this->assertDatabaseCount('payment_webhook_receipts', 1);
        }

        DB::table('payment_webhook_receipts')->insert($receipt + ['application_id' => $appB]);
        $this->assertDatabaseCount('payment_webhook_receipts', 2);
    }

    public function test_missing_payout_destination_can_hold_receivable_without_losing_it(): void
    {
        $appId = $this->application('Held Payout App');
        $accountId = DB::table('financial_accounts')->insertGetId([
            'application_id' => $appId,
            'owner_type' => 'establishment',
            'owner_id' => 77,
            'currency' => 'BRL',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payout_obligations')->insert([
            'public_id' => (string) Str::uuid(),
            'application_id' => $appId,
            'financial_account_id' => $accountId,
            'beneficiary_type' => 'establishment',
            'beneficiary_id' => 77,
            'amount_cents' => 12500,
            'currency' => 'BRL',
            'status' => 'held',
            'hold_reason' => 'payout_destination_missing',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('payout_obligations', [
            'application_id' => $appId,
            'beneficiary_id' => 77,
            'amount_cents' => 12500,
            'status' => 'held',
            'hold_reason' => 'payout_destination_missing',
        ]);
    }
}
