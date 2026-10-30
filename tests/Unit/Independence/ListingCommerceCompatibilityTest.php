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
        $event->forceFill(['id' => 20, 'app_id' => 1, 'production_id' => 10, 'kind' => 'commercial', 'created_by_user_id' => 42, 'ownership_status' => $eventOwnership]);
        $production = new Production();
        $production->forceFill(['id' => 10, 'app_id' => $appId, 'user_id' => 42, 'ownership_status' => $productionOwnership]);
        $event->setRelation('production', $production);

        return $event;
    }

    private function provenLegacyPair(int $productionAppId = 1): Event
    {
        $event = $this->pair(null, null, $productionAppId);
        $event->forceFill(['listing_source' => 'legacy_organizer', 'verification_status' => 'legacy_owner_record']);
        $event->production->forceFill(['listing_source' => 'legacy_organizer', 'verification_status' => 'legacy_owner_record']);

        return $event;
    }

    public function test_only_proven_legacy_producer_and_events_preserve_checkout(): void
    {
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair(null, null)));
        self::assertTrue($policy->allowsLegacyOrVerified($this->provenLegacyPair()));

        $event = $this->pair(null, null);
        $event->forceFill(['listing_source' => 'legacy_organizer', 'verification_status' => 'legacy_owner_record']);
        self::assertFalse($policy->allowsLegacyOrVerified($event), 'Proveniência só do evento não basta.');

        $event = $this->pair(null, null);
        $event->production->forceFill(['listing_source' => 'legacy_organizer', 'verification_status' => 'legacy_owner_record']);
        self::assertFalse($policy->allowsLegacyOrVerified($event), 'Proveniência só da produção não basta.');
    }

    public function test_curatorial_import_with_null_provenance_and_apparent_legacy_author_is_denied(): void
    {
        $policy = new ListingCommercePolicy();

        foreach ([42, null] as $apparentCreator) {
            // Importação incompleta pode copiar o ID do organizador ou deixar
            // o autor do evento nulo; ambos parecem um registro pré-Independence.
            $event = $this->pair(null, null);
            $event->forceFill([
                'created_by_user_id' => $apparentCreator,
                'listing_source' => null,
                'verification_status' => null,
                'source_url' => null,
                'extra_info' => null,
            ]);
            $event->production->forceFill([
                'user_id' => 42,
                'listing_source' => null,
                'verification_status' => null,
                'source_url' => null,
            ]);

            self::assertFalse(
                $policy->allowsLegacyOrVerified($event),
                'Importação sem proveniência não comprova legado: ' . var_export($apparentCreator, true)
            );
        }
    }

    public function test_curated_origin_without_extra_info_is_never_mistaken_for_legacy(): void
    {
        $policy = new ListingCommercePolicy();

        foreach (['event', 'production'] as $entity) {
            foreach (['curated', 'public_source_only'] as $source) {
                $event = $this->provenLegacyPair();
                $target = $entity === 'event' ? $event : $event->production;
                $target->forceFill(['listing_source' => $source]);
                self::assertFalse($policy->allowsLegacyOrVerified($event), "$entity: $source");
            }
        }
    }

    public function test_pending_or_rejected_verification_without_curation_flags_is_denied(): void
    {
        $policy = new ListingCommercePolicy();

        foreach (['event', 'production'] as $entity) {
            foreach (['pending', 'pending_claim', 'rejected', 'public_source_only', 'manual_approved'] as $status) {
                $event = $this->provenLegacyPair();
                $target = $entity === 'event' ? $event : $event->production;
                $target->forceFill(['verification_status' => $status]);
                self::assertFalse($policy->allowsLegacyOrVerified($event), "$entity: $status");
            }
        }
    }

    public function test_null_ownership_requires_real_matching_legacy_actors(): void
    {
        $policy = new ListingCommercePolicy();

        // O controller legado de eventos comerciais não preenchia sempre
        // created_by_user_id; produção com dono e vínculo persistido é aceita.
        $event = $this->provenLegacyPair();
        $event->forceFill(['created_by_user_id' => null]);
        self::assertTrue($policy->allowsLegacyOrVerified($event));

        $event->forceFill(['production_id' => 11]);
        self::assertFalse($policy->allowsLegacyOrVerified($event));

        $event = $this->provenLegacyPair();
        $event->forceFill(['created_by_user_id' => null, 'source_url' => 'https://example.test/curation']);
        self::assertFalse($policy->allowsLegacyOrVerified($event));

        $event = $this->provenLegacyPair();
        $event->production->forceFill(['source_url' => 'https://example.test/production']);
        self::assertFalse($policy->allowsLegacyOrVerified($event));

        $event = $this->provenLegacyPair();
        $event->production->forceFill(['user_id' => null]);
        self::assertFalse($policy->allowsLegacyOrVerified($event));

        $event = $this->provenLegacyPair();
        $event->forceFill(['created_by_user_id' => 43]);
        self::assertFalse($policy->allowsLegacyOrVerified($event));
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
        $event = $this->provenLegacyPair();
        $event->forceFill(['extra_info' => [
            'curation' => ['sale_mode' => 'information_only', 'ticket_checkout_enabled' => false],
        ]]);
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($event));
        self::assertFalse($policy->canSell($event));
    }

    public function test_legacy_community_meeting_never_enables_checkout(): void
    {
        $event = $this->provenLegacyPair();
        $event->forceFill(['kind' => 'community']);

        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($event));
        self::assertFalse($policy->canSell($event));
    }

    public function test_legacy_record_without_valid_application_never_enables_checkout(): void
    {
        $event = $this->provenLegacyPair(0);
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
        self::assertFalse((new ListingCommercePolicy())->allowsLegacyOrVerified($this->provenLegacyPair(2)));
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
            ['status' => null],
            ['status' => ''],
            ['status' => []],
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

    public function test_asaas_recovery_requires_matching_nonempty_customer_in_both_get_responses(): void
    {
        $guard = new \ReflectionMethod(CommerceOrderPaymentRetryService::class, 'assertAsaasRecoveryCustomer');
        $candidate = ['id' => 'pay_123', 'customer' => 'cus_123'];
        self::assertSame('cus_123', $guard->invoke(null, $candidate, $candidate));

        foreach ([null, '', [], 'cus_other'] as $customer) {
            try {
                $guard->invoke(null, $candidate, ['id' => 'pay_123', 'customer' => $customer]);
                self::fail('Identidade remota ausente ou divergente não autoriza conciliação.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        }

        try {
            $guard->invoke(null, ['id' => 'pay_123', 'customer' => null], $candidate);
            self::fail('Listagem sem customer não comprova identidade.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
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
