<?php

namespace Tests\Feature;

use App\Domain\Finance\Data\PaymentRecordData;
use App\Domain\Finance\Services\PaymentRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenericPaymentDomainIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_payment_service_is_reused_with_isolation_across_applications(): void
    {
        $first = $this->applicationFixture('payments-alpha', ['is_active' => true]);
        $second = $this->applicationFixture('payments-beta', ['is_active' => true]);

        config()->set('platform.applications.payments-alpha.payments.provider', 'provider-alpha');
        config()->set('platform.applications.payments-beta.payments.provider', 'provider-beta');

        $service = app(PaymentRecordService::class);

        $alpha = $service->persist(new PaymentRecordData(
            applicationId: $first->id,
            applicationSlug: $first->slug,
            sourceType: 'invoice',
            sourceReference: 'shared-reference',
            sourceId: 1,
            userId: null,
            currency: 'BRL',
            method: 'pix',
            status: 'pending',
            grossAmount: 100,
        ));

        $beta = $service->persist(new PaymentRecordData(
            applicationId: $second->id,
            applicationSlug: $second->slug,
            sourceType: 'invoice',
            sourceReference: 'shared-reference',
            sourceId: 1,
            userId: null,
            currency: 'BRL',
            method: 'pix',
            status: 'pending',
            grossAmount: 125,
        ));

        $this->assertNotSame($alpha->id, $beta->id);
        $this->assertSame('provider-alpha', $alpha->provider);
        $this->assertSame('provider-beta', $beta->provider);
        $this->assertSame($first->id, $alpha->app_id);
        $this->assertSame($second->id, $beta->app_id);
        $this->assertSame('100.00', $alpha->gross_amount);
        $this->assertSame('125.00', $beta->gross_amount);
    }

    public function test_payment_record_is_idempotent_inside_same_application_and_source(): void
    {
        $application = $this->applicationFixture('payments-idempotent', ['is_active' => true]);
        $service = app(PaymentRecordService::class);

        $first = $service->persist(new PaymentRecordData(
            applicationId: $application->id,
            applicationSlug: $application->slug,
            sourceType: 'charge',
            sourceReference: 'charge-42',
            sourceId: 42,
            userId: null,
            currency: 'BRL',
            method: 'pix',
            status: 'pending',
            grossAmount: 50,
        ));

        $paid = $service->persist(new PaymentRecordData(
            applicationId: $application->id,
            applicationSlug: $application->slug,
            sourceType: 'charge',
            sourceReference: 'charge-42',
            sourceId: 42,
            userId: null,
            currency: 'BRL',
            method: 'pix',
            status: 'paid',
            grossAmount: 50,
        ));

        $this->assertSame($first->id, $paid->id);
        $this->assertSame($first->public_id, $paid->public_id);
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->paid_at);
    }
}
