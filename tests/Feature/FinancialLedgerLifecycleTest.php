<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentIntent;
use App\Data\Payments\PaymentProviderResult;
use App\Models\EcosystemPayment;
use App\Models\FinancialLedgerEntry;
use App\Services\Payments\FinancialLedgerService;
use App\Services\Payments\PaymentLifecycleService;
use App\Services\Payments\PaymentReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class FinancialLedgerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_confirmation_and_refund_create_immutable_compensating_events(): void
    {
        $payment = $this->payment([
            'status' => 'pending',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'provider_fee' => 2,
            'seller_net' => 88,
            'metadata' => ['qr_code' => '000201...'],
        ]);

        $this->assertDatabaseHas('financial_ledger_entries', ['payment_id' => $payment->id, 'event_type' => 'checkout_created']);
        $this->assertDatabaseHas('financial_ledger_entries', ['payment_id' => $payment->id, 'event_type' => 'qr_generated']);

        $paidAt = now()->subDay()->setTime(14, 0);
        $payment->forceFill(['status' => 'paid', 'paid_at' => $paidAt])->save();
        $payment->touch();

        $this->assertSame(1, FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'payment_confirmed')->count());
        $this->assertDatabaseHas('financial_ledger_entries', [
            'payment_id' => $payment->id,
            'event_type' => 'payment_confirmed',
            'gross_amount' => 100,
            'platform_amount' => 10,
            'provider_amount' => 2,
            'seller_amount' => 88,
        ]);

        $payment->forceFill(['status' => 'refunded', 'refunded_at' => now()])->save();

        $this->assertDatabaseHas('financial_ledger_entries', [
            'payment_id' => $payment->id,
            'event_type' => 'payment_refunded',
            'gross_amount' => -100,
            'platform_amount' => -10,
            'provider_amount' => -2,
            'seller_amount' => -88,
        ]);

        $snapshot = app(FinancialLedgerService::class)->snapshot();
        $this->assertSame(0.0, (float) $snapshot['platform_balance']);
        $this->assertSame(100.0, (float) $snapshot['reversed_gross']);
    }

    public function test_ledger_entries_cannot_be_edited_or_deleted(): void
    {
        $payment = $this->payment(['status' => 'paid', 'paid_at' => now()]);
        $entry = FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'payment_confirmed')->firstOrFail();

        try {
            $entry->update(['gross_amount' => 999]);
            $this->fail('Ledger entry update should have been blocked.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $entry = $entry->fresh();
        try {
            $entry->delete();
            $this->fail('Ledger entry deletion should have been blocked.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    public function test_stale_pix_is_expired_without_becoming_revenue(): void
    {
        $payment = $this->payment(['status' => 'pending', 'method' => 'pix', 'gross_amount' => 399.90]);
        $payment->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $count = app(PaymentLifecycleService::class)->expireStaleOpenPayments();

        $this->assertSame(1, $count);
        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->failed_at);
        $this->assertSame(0, FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'payment_confirmed')->count());
        $this->assertSame(1, FinancialLedgerEntry::query()->where('payment_id', $payment->id)->where('event_type', 'payment_expired')->count());
    }

    public function test_reconciliation_detects_remote_payment_and_corrects_local_projection(): void
    {
        config()->set('commerce.payments.providers.fake', FakeReconciliationGateway::class);

        $payment = $this->payment([
            'provider' => 'fake',
            'provider_payment_id' => 'remote-123',
            'status' => 'pending',
            'gross_amount' => 150,
            'platform_fee' => 15,
            'provider_fee' => 0,
            'seller_net' => 135,
        ]);

        $result = app(PaymentReconciliationService::class)->reconcile($payment);

        $this->assertSame('corrected', $result);
        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('corrected', $payment->reconciliation_status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->available_at);
        $this->assertDatabaseHas('payment_reconciliations', [
            'payment_id' => $payment->id,
            'matched' => false,
            'discrepancy_code' => 'status_provider_fee_mismatch',
        ]);
        $this->assertDatabaseHas('financial_ledger_entries', [
            'payment_id' => $payment->id,
            'event_type' => 'payment_confirmed',
            'gross_amount' => 150,
        ]);
    }

    public function test_daily_close_keeps_sale_on_original_day_and_refund_on_refund_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 12:00:00'));
        $payment = $this->payment([
            'status' => 'paid',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'provider_fee' => 2,
            'seller_net' => 88,
            'paid_at' => CarbonImmutable::parse('2026-09-02 10:00:00'),
        ]);
        $payment->forceFill(['status' => 'refunded', 'refunded_at' => CarbonImmutable::parse('2026-09-03 11:00:00')])->save();

        $closing = app(FinancialLedgerService::class)->dailyClose(
            CarbonImmutable::parse('2026-09-02')->startOfDay(),
            CarbonImmutable::parse('2026-09-03')->endOfDay(),
        );

        $days = collect($closing['days'])->keyBy('day');
        $this->assertSame(100.0, (float) $days['2026-09-02']['receipts']);
        $this->assertSame(0.0, (float) $days['2026-09-02']['reversals']);
        $this->assertSame(100.0, (float) $days['2026-09-03']['reversals']);
        $this->assertSame(0.0, (float) $days['2026-09-03']['receipts']);

        CarbonImmutable::setTestNow();
    }

    private function payment(array $overrides = []): EcosystemPayment
    {
        return EcosystemPayment::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'app_id' => null,
            'app_slug' => 'financial-test',
            'provider' => 'mercadopago',
            'provider_payment_id' => 'provider-' . Str::uuid(),
            'source_type' => 'test',
            'source_reference' => 'ref-' . Str::uuid(),
            'currency' => 'BRL',
            'method' => 'pix',
            'status' => 'pending',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'provider_fee' => 2,
            'seller_net' => 88,
            'metadata' => [],
        ], $overrides));
    }
}

class FakeReconciliationGateway implements PaymentGateway
{
    public function name(): string { return 'fake'; }
    public function isConfigured(): bool { return true; }
    public function supportedMethods(): array { return ['pix']; }
    public function initiate(PaymentIntent $intent): PaymentProviderResult { throw new \RuntimeException('Not used.'); }
    public function retrieve(?string $providerPaymentId, string $externalReference): ?PaymentProviderResult
    {
        return new PaymentProviderResult(
            providerPaymentId: $providerPaymentId ?: 'remote-123',
            status: 'paid',
            providerStatus: 'approved',
            providerFee: 3,
            externalReference: $externalReference,
            grossAmount: 150,
            availableAt: '2026-09-03T12:00:00-03:00',
        );
    }
    public function retrieveById(string $providerPaymentId): PaymentProviderResult { return $this->retrieve($providerPaymentId, 'test'); }
    public function validateWebhook(array $headers, string $resourceId): bool { return true; }
}
