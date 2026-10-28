<?php

declare(strict_types=1);

namespace Tests\Unit\Independence;

use App\Domain\Commerce\Services\CommerceOrderPaymentRetryService;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ListingCommerceCompatibilityTest extends TestCase
{
    private function pair(?string $eventOwnership, ?string $productionOwnership, int $appId = 1): Event
    {
        $event = new Event();
        $event->forceFill(['app_id' => 1, 'kind' => 'commercial', 'ownership_status' => $eventOwnership]);
        $production = new Production();
        $production->forceFill(['app_id' => $appId, 'ownership_status' => $productionOwnership]);
        $event->setRelation('production', $production);

        return $event;
    }

    public function test_legacy_producer_and_events_without_markers_preserve_checkout(): void
    {
        self::assertTrue((new ListingCommercePolicy())->allowsLegacyOrVerified($this->pair(null, null)));
    }

    public function test_unclaimed_event_or_production_never_enables_checkout(): void
    {
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair('unclaimed', null)));
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair(null, 'unclaimed')));
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair('pending_claim', 'verified')));
    }

    public function test_legacy_event_with_curatorial_metadata_cannot_sell(): void
    {
        $event = $this->pair(null, null);
        $event->forceFill(['extra_info' => [
            'curation' => ['sale_mode' => 'information_only', 'ticket_checkout_enabled' => false],
        ]]);
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($event));
        self::assertFalse($policy->canSell($event));
    }

    public function test_legacy_community_meeting_never_enables_checkout(): void
    {
        $event = $this->pair(null, null);
        $event->forceFill(['kind' => 'community']);

        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($event));
        self::assertFalse($policy->canSell($event));
    }

    public function test_legacy_record_without_valid_application_never_enables_checkout(): void
    {
        $event = $this->pair(null, null, 0);
        $event->forceFill(['app_id' => 0]);

        self::assertFalse((new ListingCommercePolicy())->allowsLegacyOrVerified($event));
    }

    private function retryFixture(int $eventAppId = 1, int $productionAppId = 1, int $paymentAppId = 1, string $paymentStatus = 'failed'): CommerceOrder
    {
        $order = new CommerceOrder();
        $order->forceFill(['id' => 70, 'app_id' => 1, 'production_id' => 80, 'status' => 'pending']);
        $event = new Event();
        $event->forceFill(['app_id' => $eventAppId, 'production_id' => 80, 'kind' => 'commercial']);
        $production = new Production();
        $production->forceFill(['app_id' => $productionAppId]);
        $payment = new CommercePayment();
        $payment->forceFill(['app_id' => $paymentAppId, 'order_id' => 70, 'status' => $paymentStatus]);
        $order->setRelation('event', $event);
        $order->setRelation('production', $production);
        $order->setRelation('payments', collect([$payment]));

        return $order;
    }

    public function test_retry_rejects_pending_order_with_paid_payment_before_any_provider_call(): void
    {
        $method = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertRetrySafety');
        try {
            $method->invoke(null, $this->retryFixture(paymentStatus: 'paid'), 1);
            self::fail('Uma cobrança já paga nunca pode gerar uma nova tentativa.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_retry_rejects_cross_app_event_production_and_payment(): void
    {
        $method = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertRetrySafety');
        foreach ([
            $this->retryFixture(eventAppId: 2),
            $this->retryFixture(productionAppId: 2),
            $this->retryFixture(paymentAppId: 2),
        ] as $order) {
            try {
                $method->invoke(null, $order, 1);
                self::fail('A divergência de aplicação deve impedir retry.');
            } catch (HttpException $exception) {
                self::assertContains($exception->getStatusCode(), [403, 409]);
            }
        }

        $method->invoke(null, $this->retryFixture(), 1);
        self::assertTrue(true);
    }

    public function test_cross_application_record_never_allows_checkout(): void
    {
        self::assertFalse((new ListingCommercePolicy())->allowsLegacyOrVerified($this->pair(null, null, 2)));
    }

    public function test_retry_never_reuses_an_existing_payment_with_a_different_method(): void
    {
        $payment = new CommercePayment();
        $payment->forceFill(['method' => 'card', 'status' => 'pending']);
        $guard = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertExistingPaymentMethod');

        try {
            $guard->invoke(null, $payment, 'pix');
            self::fail('Uma cobrança de cartão não pode ser apresentada como PIX.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        $guard->invoke(null, $payment, 'card');
        self::assertTrue(true);
    }

    public function test_asaas_retry_accepts_only_matching_remote_payment_identity(): void
    {
        $guard = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertAsaasPaymentMatches');
        $remote = [
            'id' => 'pay_123',
            'customer' => 'cus_123',
            'billingType' => 'PIX',
            'value' => 129.90,
            'externalReference' => 'order_123',
            'status' => 'PENDING',
        ];

        foreach (['pix' => 'PIX', 'card' => 'CREDIT_CARD', 'boleto' => 'BOLETO'] as $method => $billingType) {
            $guard->invoke(null, array_replace($remote, ['billingType' => $billingType]), 'cus_123', $method, 129.90, 'order_123');
            self::assertTrue(true);
        }

        $invalid = [
            ['id' => ''],
            ['id' => []],
            ['customer' => 'cus_other'],
            ['customer' => []],
            ['billingType' => 'CREDIT_CARD'],
            ['billingType' => null],
            ['externalReference' => 'order_other'],
            ['externalReference' => []],
            ['value' => 129.91],
            ['value' => 'invalid'],
            ['value' => null],
            ['value' => INF],
            ['deleted' => true],
        ];

        foreach ($invalid as $changes) {
            try {
                $guard->invoke(null, array_replace($remote, $changes), 'cus_123', 'pix', 129.90, 'order_123');
                self::fail('O Asaas retornou uma cobrança divergente: ' . json_encode($changes));
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        }
    }

    public function test_mercadopago_pix_retry_rejects_remote_payment_from_other_order_app_or_amount(): void
    {
        $order = $this->retryFixture();
        $order->forceFill(['public_id' => 'order_70', 'total' => 129.90]);
        $remote = [
            'id' => 123456789,
            'external_reference' => 'order_70',
            'payment_method_id' => 'pix',
            'transaction_amount' => 129.90,
            'status' => 'pending',
            'metadata' => [
                'application_id' => 1,
                'order_id' => 70,
                'order_public_id' => 'order_70',
                'organization_id' => 80,
            ],
        ];
        $guard = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertMercadoPagoPixPaymentMatches');
        $guard->invoke(null, $remote, $order);
        self::assertTrue(true);

        $invalid = [
            ['id' => null],
            ['id' => 'not-an-id'],
            ['external_reference' => 'order_other'],
            ['external_reference' => null],
            ['payment_method_id' => 'credit_card'],
            ['payment_method_id' => null],
            ['transaction_amount' => 129.91],
            ['transaction_amount' => null],
            ['transaction_amount' => INF],
            ['status' => null],
            ['metadata' => []],
            ['metadata' => array_replace($remote['metadata'], ['application_id' => 2])],
            ['metadata' => array_replace($remote['metadata'], ['order_id' => 71])],
            ['metadata' => array_replace($remote['metadata'], ['order_public_id' => 'other'])],
            ['metadata' => array_replace($remote['metadata'], ['organization_id' => 81])],
        ];

        foreach ($invalid as $changes) {
            try {
                $guard->invoke(null, array_replace($remote, $changes), $order);
                self::fail('PIX remoto divergente foi aceito: ' . json_encode($changes));
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        }
    }
}
