<?php

namespace Tests\Feature;

use App\Data\Payments\PaymentProviderResult;
use App\Models\EcosystemPayment;
use App\Services\Commerce\CommercePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class PaymentFinancialTimestampStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_repeated_provider_sync_does_not_move_original_paid_timestamp(): void
    {
        Carbon::setTestNow('2026-09-03 09:00:00');
        $originalPaidAt = now()->subDay();
        $payment = $this->payment([
            'status' => 'paid',
            'paid_at' => $originalPaidAt,
        ]);

        Carbon::setTestNow('2026-09-03 12:00:00');
        $this->applyProviderResult($payment, new PaymentProviderResult(
            providerPaymentId: 'provider-123',
            status: 'paid',
            providerStatus: 'approved',
            providerFee: 2.50,
        ));

        $payment->refresh();

        $this->assertTrue($payment->paid_at->equalTo($originalPaidAt));
        $this->assertSame('paid', $payment->status);
        $this->assertSame('2.50', $payment->provider_fee);
    }

    public function test_repeated_refund_notifications_preserve_first_refund_timestamp(): void
    {
        Carbon::setTestNow('2026-09-03 09:00:00');
        $payment = $this->payment([
            'status' => 'paid',
            'paid_at' => now()->subHour(),
        ]);

        Carbon::setTestNow('2026-09-03 10:00:00');
        $refund = new PaymentProviderResult(
            providerPaymentId: 'provider-456',
            status: 'refunded',
            providerStatus: 'refunded',
            providerFee: 2.50,
        );
        $this->applyProviderResult($payment, $refund);
        $firstRefundAt = $payment->fresh()->refunded_at;

        Carbon::setTestNow('2026-09-03 14:00:00');
        $this->applyProviderResult($payment->fresh(), $refund);
        $payment->refresh();

        $this->assertTrue($payment->refunded_at->equalTo($firstRefundAt));
        $this->assertSame('refunded', $payment->status);
    }

    private function payment(array $overrides = []): EcosystemPayment
    {
        return EcosystemPayment::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'app_id' => null,
            'app_slug' => 'financial-test-app',
            'provider' => 'mercadopago',
            'provider_payment_id' => null,
            'source_type' => 'financial_test',
            'source_reference' => 'test-' . Str::uuid(),
            'source_id' => null,
            'user_id' => null,
            'production_id' => null,
            'establishment_id' => null,
            'currency' => 'BRL',
            'method' => 'pix',
            'status' => 'pending',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'provider_fee' => 0,
            'seller_net' => 90,
            'metadata' => [],
        ], $overrides));
    }

    private function applyProviderResult(EcosystemPayment $payment, PaymentProviderResult $result): void
    {
        $service = (new ReflectionClass(CommercePaymentService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(CommercePaymentService::class))->getMethod('applyProviderResult');
        $method->setAccessible(true);
        $method->invoke($service, $payment, $result);
    }
}
