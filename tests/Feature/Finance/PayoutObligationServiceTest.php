<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FinancialLedgerService;
use App\Domain\Finance\Services\PayoutObligationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class PayoutObligationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserves_sale_as_held_receivable_when_destination_is_missing(): void
    {
        [$appId, $cash, $receivable] = $this->fixture('payout-held');
        $transaction = app(FinancialLedgerService::class)->post($appId, 'payment:mp:held-1', [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 10000, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 10000, 'role' => 'receivable'],
        ], ['provider' => 'mercado_pago', 'provider_reference' => 'held-1']);

        $service = app(PayoutObligationService::class);
        $first = $service->create($appId, $transaction->id, $receivable, 'establishment', 10, 10000, false);
        $second = $service->create($appId, $transaction->id, $receivable, 'establishment', 10, 10000, false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('held', $first->status);
        $this->assertSame('payout_destination_missing', $first->hold_reason);
        $this->assertSame(1, DB::table('payout_obligations')->where('application_id', $appId)->count());

        $released = $service->releaseHeld($appId, $first->id, true);
        $this->assertSame('eligible', $released->status);
        $this->assertNull($released->hold_reason);
        $this->assertNotNull($released->eligible_at);
    }

    public function test_does_not_release_held_obligation_without_payout_destination(): void
    {
        [$appId, $cash, $receivable] = $this->fixture('payout-release-guard');
        $transaction = app(FinancialLedgerService::class)->post($appId, 'payment:mp:release-guard', [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 12500, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 12500, 'role' => 'receivable'],
        ]);

        $service = app(PayoutObligationService::class);
        $obligation = $service->create($appId, $transaction->id, $receivable, 'establishment', 15, 12500, false);

        try {
            $service->releaseHeld($appId, $obligation->id);
            $this->fail('Held payout obligation was released without a payout destination.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Payout destination must be available before releasing a held obligation.', $exception->getMessage());
        }

        $persisted = DB::table('payout_obligations')->where('id', $obligation->id)->first();
        $this->assertSame('held', $persisted->status);
        $this->assertSame('payout_destination_missing', $persisted->hold_reason);
        $this->assertNull($persisted->eligible_at);
    }

    public function test_rejects_conflicting_retry_without_changing_amount_owed(): void
    {
        [$appId, $cash, $receivable] = $this->fixture('payout-conflict');
        $transaction = app(FinancialLedgerService::class)->post($appId, 'payment:mp:conflict-payout', [
            ['financial_account_id' => $cash, 'direction' => 'debit', 'amount_cents' => 7500, 'role' => 'gross'],
            ['financial_account_id' => $receivable, 'direction' => 'credit', 'amount_cents' => 7500, 'role' => 'receivable'],
        ]);

        $service = app(PayoutObligationService::class);
        $service->create($appId, $transaction->id, $receivable, 'establishment', 20, 7500, false);

        $this->expectException(InvalidArgumentException::class);
        try {
            $service->create($appId, $transaction->id, $receivable, 'establishment', 20, 8000, false);
        } finally {
            $this->assertSame(7500, (int) DB::table('payout_obligations')->where('application_id', $appId)->value('amount_cents'));
        }
    }

    public function test_rejects_cross_application_source_or_account(): void
    {
        [$appA, $cashA, $receivableA] = $this->fixture('payout-app-a');
        [$appB, , $receivableB] = $this->fixture('payout-app-b');
        $transaction = app(FinancialLedgerService::class)->post($appA, 'payment:cross-app-payout', [
            ['financial_account_id' => $cashA, 'direction' => 'debit', 'amount_cents' => 5000, 'role' => 'gross'],
            ['financial_account_id' => $receivableA, 'direction' => 'credit', 'amount_cents' => 5000, 'role' => 'receivable'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(PayoutObligationService::class)->create($appB, $transaction->id, $receivableB, 'establishment', 30, 5000, false);
    }

    private function fixture(string $slug): array
    {
        $appId = DB::table('applications')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cash = $this->account($appId, 'platform', 1);
        $receivable = $this->account($appId, 'beneficiary', random_int(100, 999999));

        return [$appId, $cash, $receivable];
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
