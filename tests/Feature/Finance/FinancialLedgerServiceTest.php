<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FinancialLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class FinancialLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_balanced_transaction_once_for_same_idempotency_key(): void
    {
        $appId = $this->application('ledger-a');
        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', 10);
        $service = app(FinancialLedgerService::class);

        $entries = [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 12500, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 12500, 'role' => 'receivable'],
        ];

        $first = $service->post($appId, 'payment:mp:123', $entries, ['provider' => 'mercado_pago', 'provider_reference' => '123']);
        $second = $service->post($appId, 'payment:mp:123', $entries, ['provider' => 'mercado_pago', 'provider_reference' => '123']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('financial_transactions')->where('application_id', $appId)->count());
        $this->assertSame(2, DB::table('financial_ledger_entries')->where('financial_transaction_id', $first->id)->count());
    }

    public function test_replays_same_idempotency_key_when_nested_audit_metadata_order_differs(): void
    {
        $appId = $this->application('ledger-metadata-order');
        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', 10);
        $entries = [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 2200, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 2200, 'role' => 'receivable'],
        ];
        $service = app(FinancialLedgerService::class);

        $first = $service->post($appId, 'refund:order:2', $entries, [
            'type' => 'refund',
            'metadata' => [
                'order_id' => 2,
                'reconciliation' => ['batch' => 'nightly', 'attempt' => 1],
            ],
        ]);
        $second = $service->post($appId, 'refund:order:2', $entries, [
            'type' => 'refund',
            'metadata' => [
                'reconciliation' => ['attempt' => 1, 'batch' => 'nightly'],
                'order_id' => 2,
            ],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('financial_transactions')->where('application_id', $appId)->count());
    }

    public function test_rejects_same_idempotency_key_for_different_financial_operation(): void
    {
        $appId = $this->application('ledger-idempotency-conflict');
        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', 10);
        $service = app(FinancialLedgerService::class);

        $service->post($appId, 'payment:mp:conflict', [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 12500, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 12500, 'role' => 'receivable'],
        ], ['provider' => 'mercado_pago', 'provider_reference' => 'payment-1']);

        try {
            $service->post($appId, 'payment:mp:conflict', [
                ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 13000, 'role' => 'gross'],
                ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 13000, 'role' => 'receivable'],
            ], ['provider' => 'mercado_pago', 'provider_reference' => 'payment-2']);
            $this->fail('Expected conflicting idempotency reuse to be rejected.');
        } catch (InvalidArgumentException) {
            $transaction = DB::table('financial_transactions')->where('application_id', $appId)->first();
            $this->assertNotNull($transaction);
            $this->assertSame('payment-1', $transaction->provider_reference);
            $this->assertSame(1, DB::table('financial_transactions')->where('application_id', $appId)->count());
            $this->assertSame(25000, (int) DB::table('financial_ledger_entries')->where('financial_transaction_id', $transaction->id)->sum('amount_cents'));
        }
    }

    public function test_rejects_same_idempotency_key_for_different_audit_metadata(): void
    {
        $appId = $this->application('ledger-metadata-conflict');
        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', 10);
        $entries = [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 1000, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 1000, 'role' => 'receivable'],
        ];
        $service = app(FinancialLedgerService::class);

        $service->post($appId, 'refund:order:1', $entries, [
            'type' => 'refund',
            'metadata' => ['order_id' => 1, 'reason' => 'customer_request'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->post($appId, 'refund:order:1', $entries, [
            'type' => 'refund',
            'metadata' => ['reason' => 'customer_request', 'order_id' => 2],
        ]);
    }

    public function test_rejects_unbalanced_transaction_without_partial_write(): void
    {
        $appId = $this->application('ledger-b');
        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', 20);

        try {
            app(FinancialLedgerService::class)->post($appId, 'unbalanced', [
                ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 10000, 'role' => 'gross'],
                ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 9000, 'role' => 'receivable'],
            ]);
            $this->fail('Expected unbalanced transaction to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('financial_transactions')->where('application_id', $appId)->count());
            $this->assertSame(0, DB::table('financial_ledger_entries')->where('application_id', $appId)->count());
        }
    }

    public function test_rejects_account_from_another_application(): void
    {
        $appA = $this->application('ledger-c');
        $appB = $this->application('ledger-d');
        $cashA = $this->account($appA, 'platform', 1);
        $foreign = $this->account($appB, 'beneficiary', 30);

        $this->expectException(InvalidArgumentException::class);

        app(FinancialLedgerService::class)->post($appA, 'cross-app', [
            ['financial_account_id' => $cashA, 'direction' => 'debit', 'amount_cents' => 5000, 'role' => 'gross'],
            ['financial_account_id' => $foreign, 'direction' => 'credit', 'amount_cents' => 5000, 'role' => 'receivable'],
        ]);
    }

    private function application(string $slug): int
    {
        return DB::table('applications')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(int $applicationId, string $ownerType, int $ownerId): int
    {
        return DB::table('financial_accounts')->insertGetId([
            'application_id' => $applicationId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'currency' => 'BRL',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
