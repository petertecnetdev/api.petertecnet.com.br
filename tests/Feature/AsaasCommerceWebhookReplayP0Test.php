<?php

namespace Tests\Feature;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Services\AsaasCommerceWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AsaasCommerceWebhookReplayP0Test extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_da_reserva_financeira_permite_nova_tentativa_sem_replay_duplo(): void
    {
        $service = app(AsaasCommerceWebhookService::class);
        $reserve = new \ReflectionMethod(AsaasCommerceWebhookService::class, 'startReceipt');
        $eventId = 'evt-rollback-reserva-financeira-p0';

        // A reserva participa da transação chamadora: se a operação falhar
        // antes do commit, não pode deixar um recibo fantasma bloqueando a
        // reconciliação legítima. Não equivale a concorrência MySQL real.
        try {
            DB::transaction(function () use ($service, $reserve, $eventId): void {
                $this->assertTrue($reserve->invoke($service, $eventId, 'PAYMENT_RECEIVED', [
                    'id' => $eventId,
                ]));

                throw new RuntimeException('falha simulada antes do commit financeiro');
            });
            $this->fail('A falha simulada deveria abortar a transação.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha simulada antes do commit financeiro', $exception->getMessage());
        }

        $this->assertDatabaseMissing('financial_webhook_events', [
            'provider' => 'asaas',
            'event_id' => $eventId,
        ]);

        $this->assertTrue($reserve->invoke($service, $eventId, 'PAYMENT_RECEIVED', [
            'id' => $eventId,
        ]));
        $this->assertFalse($reserve->invoke($service, $eventId, 'PAYMENT_RECEIVED', [
            'id' => $eventId,
        ]));
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas',
            'event_id' => $eventId,
            'processed_at' => null,
        ]);
        $this->assertDatabaseCount('financial_webhook_events', 1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_webhook_receipt_is_reserved_only_once_before_processing_finishes(): void
    {
        $service = app(AsaasCommerceWebhookService::class);
        $reserve = new \ReflectionMethod(AsaasCommerceWebhookService::class, 'startReceipt');

        $this->assertTrue($reserve->invoke($service, 'evt-reserva-p0', 'PAYMENT_RECEIVED', [
            'id' => 'evt-reserva-p0',
        ]));

        // Simula uma segunda entrega antes de processed_at ser preenchido.
        // O comportamento anterior retornava true nas duas chamadas.
        $this->assertFalse($reserve->invoke($service, 'evt-reserva-p0', 'PAYMENT_RECEIVED', [
            'id' => 'evt-reserva-p0',
        ]));

        $this->assertDatabaseCount('financial_webhook_events', 1);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas',
            'event_id' => 'evt-reserva-p0',
            'processed_at' => null,
        ]);
    }

    public function test_pending_receipt_from_previous_attempt_never_reprocesses_without_reconciliation(): void
    {
        DB::table('financial_webhook_events')->insert([
            'provider' => 'asaas',
            'event_id' => 'evt-pendente-p0',
            'event_type' => 'PAYMENT_RECEIVED',
            'payload' => json_encode(['id' => 'evt-pendente-p0'], JSON_THROW_ON_ERROR),
            'processed_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        app(AsaasCommerceWebhookService::class)->process([
            'id' => 'evt-pendente-p0',
            'event' => 'PAYMENT_RECEIVED',
            'payment' => ['id' => 'pay-inexistente'],
        ]);

        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas',
            'event_id' => 'evt-pendente-p0',
            'processed_at' => null,
        ]);
        $this->assertDatabaseCount('financial_webhook_events', 1);
    }

    public function test_replayed_processed_event_does_not_mutate_receipt_or_execute_again(): void
    {
        $service = app(AsaasCommerceWebhookService::class);
        $service->process([
            'id' => 'evt-replay-p0',
            'event' => 'EVENTO_DESCONHECIDO',
        ]);

        $this->assertNotNull(DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', 'evt-replay-p0')
            ->value('processed_at'));

        DB::table('financial_webhook_events')
            ->where('provider', 'asaas')
            ->where('event_id', 'evt-replay-p0')
            ->update(['payload' => '{"marcador":"imutavel"}']);

        // MySQL pode normalizar a representação textual de JSON. Comparar o
        // conteúdo semântico e preservar também os bytes gravados no replay.
        $receiptBefore = DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', 'evt-replay-p0')
            ->first(['payload', 'processed_at']);
        $this->assertNotNull($receiptBefore);
        $payloadBefore = json_decode((string) $receiptBefore->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['marcador' => 'imutavel'], $payloadBefore);
        $this->assertNotNull($receiptBefore->processed_at);

        $service->process([
            'id' => 'evt-replay-p0',
            'event' => 'EVENTO_DESCONHECIDO',
            'dateCreated' => '2026-10-29T19:06:00-03:00',
        ]);

        $this->assertDatabaseCount('financial_webhook_events', 1);
        $receiptAfter = DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', 'evt-replay-p0')
            ->first(['payload', 'processed_at']);
        $this->assertNotNull($receiptAfter);
        $this->assertSame(
            $payloadBefore,
            json_decode((string) $receiptAfter->payload, true, 512, JSON_THROW_ON_ERROR),
            'Replay não pode modificar o conteúdo do payload persistido.'
        );
        $this->assertSame($receiptBefore->payload, $receiptAfter->payload, 'Replay não pode regravar o payload.');
        $this->assertSame($receiptBefore->processed_at, $receiptAfter->processed_at, 'Replay não pode alterar processed_at.');
    }

    public function test_distinct_webhook_ids_remain_independently_processable(): void
    {
        $service = app(AsaasCommerceWebhookService::class);

        foreach (['evt-primeiro-p0', 'evt-segundo-p0'] as $id) {
            $service->process(['id' => $id, 'event' => 'EVENTO_DESCONHECIDO']);
        }

        $this->assertDatabaseCount('financial_webhook_events', 2);
        $this->assertSame(2, DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->whereNotNull('processed_at')->count());
    }
    public function test_paid_webhook_requires_remote_get_before_accepting_valid_identity(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $notification = $this->paidAsaasPayload();

        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));

        $verified = $this->verifyPaid($payment, $order, $notification);

        $this->assertSame('pay-asaas-p0', $verified['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/payments/pay-asaas-p0'));
    }

    public function test_missing_webhook_identity_fields_block_before_remote_get(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        Http::fake();

        foreach (['id', 'externalReference', 'value', 'billingType', 'status'] as $field) {
            $notification = $this->paidAsaasPayload();
            unset($notification[$field]);
            $this->assertRejected(fn () => $this->verifyPaid($payment, $order, $notification));
        }

        Http::assertNothingSent();
    }

    public function test_remote_get_missing_or_divergent_identity_never_proves_settlement(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $remote = $this->paidAsaasPayload();
        Http::fake(function () use (&$remote) {
            return Http::response($remote, 200);
        });

        foreach (['id', 'externalReference', 'value', 'billingType', 'status'] as $field) {
            $remote = $this->paidAsaasPayload();
            unset($remote[$field]);
            $this->assertRejected(fn () => $this->verifyPaid($payment, $order, $this->paidAsaasPayload()));
        }

        foreach ([
            ['id' => 'pay-de-outro-pedido'],
            ['externalReference' => 'pedido-de-outra-aplicacao'],
            ['value' => 99.99],
            ['billingType' => 'BOLETO'],
            ['status' => 'PENDING'],
        ] as $divergence) {
            $remote = array_replace($this->paidAsaasPayload(), $divergence);
            $this->assertRejected(fn () => $this->verifyPaid($payment, $order, $this->paidAsaasPayload()));
        }

        Http::assertSentCount(10);
    }

    public function test_local_app_order_and_payment_amount_must_match_before_get(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        Http::fake();

        foreach (['app_id', 'order_id', 'amount', 'payment_method', 'provider_payment_id'] as $field) {
            [$payment, $order] = $this->localPaymentPair();
            if ($field === 'app_id') {
                $order->app_id = 99;
            } elseif ($field === 'order_id') {
                $payment->order_id = 999;
            } elseif ($field === 'amount') {
                $payment->amount = 21.00;
            } elseif ($field === 'payment_method') {
                $order->payment_method = 'boleto';
            } else {
                $payment->provider_payment_id = 'pay-de-outro-pedido';
            }

            $this->assertRejected(fn () => $this->verifyPaid($payment, $order, $this->paidAsaasPayload()));
        }

        Http::assertNothingSent();
    }

    public function test_gateway_failure_does_not_allow_local_paid_confirmation(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        Http::fake(fn () => Http::response(['errors' => [['description' => 'Indisponível']]], 503));

        $this->assertRejected(fn () => $this->verifyPaid($payment, $order, $this->paidAsaasPayload()));
        Http::assertSentCount(1);
    }

    public function test_checkout_paid_without_id_fails_closed_before_lookup_or_settlement(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        Http::fake();

        $this->assertRejected(fn () => app(AsaasCommerceWebhookService::class)->process([
            'id' => 'evt-checkout-sem-id-p0',
            'event' => 'CHECKOUT_PAID',
            'checkout' => [
                'externalReference' => 'pedido-de-outra-pessoa',
                'status' => 'PAID',
                'items' => [['value' => 20.00, 'quantity' => 1]],
            ],
        ]));

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas',
            'event_id' => 'evt-checkout-sem-id-p0',
            'processed_at' => null,
        ]);
    }

    public function test_checkout_paid_requires_exact_checkout_reference_status_and_items_before_get(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $payment->provider_txid = 'checkout-asaas-p0';
        Http::fake();

        foreach ([
            ['id' => ''],
            ['id' => 'checkout-de-outro-pedido'],
            ['externalReference' => null],
            ['externalReference' => 'pedido-de-outro-app'],
            ['status' => null],
            ['status' => 'PENDING'],
            ['items' => []],
            ['items' => [['value' => 10.00, 'quantity' => 1]]],
            ['items' => [['value' => 20.00, 'quantity' => 0]]],
            ['items' => [['value' => 'invalido', 'quantity' => 1]]],
        ] as $change) {
            $this->assertRejected(fn () => $this->verifyCheckoutPaid(
                $payment, $order, array_replace($this->paidCheckoutPayload(), $change)
            ));
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_checkout_paid_blocks_cross_app_order_and_unbound_payment_before_get(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        Http::fake();

        foreach (['app_id', 'order_id', 'amount', 'order_total', 'provider_payment_id', 'provider_txid'] as $field) {
            [$payment, $order] = $this->localPaymentPair();
            $payment->provider_txid = 'checkout-asaas-p0';
            if ($field === 'app_id') {
                $order->app_id = 99;
            } elseif ($field === 'order_id') {
                $payment->order_id = 999;
            } elseif ($field === 'amount') {
                $payment->amount = 21.00;
            } elseif ($field === 'order_total') {
                $order->total = 21.00;
            } elseif ($field === 'provider_payment_id') {
                $payment->provider_payment_id = null;
            } else {
                $payment->provider_txid = null;
            }

            $this->assertRejected(fn () => $this->verifyCheckoutPaid(
                $payment, $order, $this->paidCheckoutPayload()
            ));
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_checkout_paid_remote_get_must_prove_payment_id_reference_value_and_status(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $payment->provider_txid = 'checkout-asaas-p0';
        $remote = $this->paidAsaasPayload();
        Http::fake(function () use (&$remote) {
            return Http::response($remote, 200);
        });

        foreach (['id', 'externalReference', 'value', 'billingType', 'status'] as $field) {
            $remote = $this->paidAsaasPayload();
            unset($remote[$field]);
            $this->assertRejected(fn () => $this->verifyCheckoutPaid(
                $payment, $order, $this->paidCheckoutPayload()
            ));
        }

        foreach ([
            ['id' => 'pay-outro'],
            ['externalReference' => 'pedido-outro'],
            ['value' => 19.99],
            ['billingType' => 'BOLETO'],
            ['status' => 'PENDING'],
        ] as $change) {
            $remote = array_replace($this->paidAsaasPayload(), $change);
            $this->assertRejected(fn () => $this->verifyCheckoutPaid(
                $payment, $order, $this->paidCheckoutPayload()
            ));
        }

        Http::assertSentCount(10);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_checkout_paid_legitimate_binding_requires_one_remote_get_and_no_post(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $payment->provider_txid = 'checkout-asaas-p0';
        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));

        $verified = $this->verifyCheckoutPaid($payment, $order, $this->paidCheckoutPayload());

        $this->assertSame('pay-asaas-p0', $verified['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/payments/pay-asaas-p0'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_checkout_paid_gateway_failure_cannot_prove_settlement(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        [$payment, $order] = $this->localPaymentPair();
        $payment->provider_txid = 'checkout-asaas-p0';
        Http::fake(fn () => Http::response(['errors' => [['description' => 'Indisponível']]], 503));

        $this->assertRejected(fn () => $this->verifyCheckoutPaid(
            $payment, $order, $this->paidCheckoutPayload()
        ));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
    }


    public function test_checkout_paid_persisted_order_settles_once_and_replay_never_duplicates_ledger_or_qr(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order, $item] = $this->persistedPaidCheckoutFixture();
        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));

        $service = app(AsaasCommerceWebhookService::class);
        $event = [
            'id' => 'evt-checkout-integrado-p0',
            'event' => 'CHECKOUT_PAID',
            'checkout' => $this->paidCheckoutPayload(),
        ];
        $service->process($event);

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'app_id' => $order->app_id, 'status' => 'paid']);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'app_id' => $order->app_id, 'status' => 'paid']);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas', 'event_id' => $event['id'], 'event_type' => 'CHECKOUT_PAID',
        ]);
        $this->assertNotNull(DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', $event['id'])->value('processed_at'));
        $this->assertSame(1, DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->where('status', 'issued')->count());
        $token = (string) DB::table('event_passes')->where('commerce_order_item_id', $item->id)->value('token');
        $this->assertTrue(str_starts_with($token, 'PASS-'), 'O ingresso deve conter token de QR emitido.');
        $this->assertEqualsCanonicalizing(
            ['gross_sale', 'platform_fee', 'producer_credit'],
            DB::table('ledger_entries')->where('payment_id', $payment->id)->pluck('type')->all()
        );

        // Mesmo event_id e outro webhook legítimo para a mesma cobrança:
        // nenhum segundo QR, nenhuma segunda linha de ledger.
        $service->process($event);
        $service->process(array_replace($event, ['id' => 'evt-checkout-integrado-replay-p0']));

        $this->assertSame(1, DB::table('event_passes')->where('commerce_order_item_id', $item->id)->count());
        $this->assertSame($token, (string) DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->value('token'));
        $this->assertSame(3, DB::table('ledger_entries')->where('payment_id', $payment->id)->count());
        $this->assertSame(2, DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->whereNotNull('processed_at')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/payments/pay-asaas-p0'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_checkout_paid_persisted_order_with_invalid_binding_has_zero_financial_effects(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order, $item] = $this->persistedPaidCheckoutFixture();
        Http::fake();

        $event = [
            'id' => 'evt-checkout-invalido-integrado-p0',
            'event' => 'CHECKOUT_PAID',
            'checkout' => array_replace($this->paidCheckoutPayload(), [
                'externalReference' => 'pedido-de-outro-app',
            ]),
        ];
        $service = app(AsaasCommerceWebhookService::class);
        $this->assertRejected(fn () => $service->process($event));

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas', 'event_id' => $event['id'], 'processed_at' => null,
        ]);
        Http::assertNothingSent();

        // Recibo pendente não pode ser reaproveitado para liquidar no replay.
        $service->process(array_replace($event, ['checkout' => $this->paidCheckoutPayload()]));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_payment_received_pix_sem_checkout_liquida_uma_vez_com_get_remoto_e_replay(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order, $item] = $this->persistedPaidCheckoutFixture();
        $payment->update(['provider_txid' => null]);
        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));

        $service = app(AsaasCommerceWebhookService::class);
        $event = [
            'id' => 'evt-payment-pix-integrado-p0',
            'event' => 'PAYMENT_RECEIVED',
            'payment' => $this->paidAsaasPayload(),
        ];
        $service->process($event);

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('commerce_payments', [
            'id' => $payment->id, 'status' => 'paid', 'provider_txid' => null,
        ]);
        $this->assertSame(1, DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->where('status', 'issued')->count());
        $token = (string) DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->value('token');
        $this->assertTrue(str_starts_with($token, 'PASS-'));
        $this->assertEqualsCanonicalizing(
            ['gross_sale', 'platform_fee', 'producer_credit'],
            DB::table('ledger_entries')->where('payment_id', $payment->id)->pluck('type')->all()
        );

        // Reentrega com o mesmo ID e nova notificação do mesmo pagamento:
        // sem checkout, não podem duplicar liquidação nem QR.
        $service->process($event);
        $service->process(array_replace($event, ['id' => 'evt-payment-pix-replay-p0']));

        $this->assertSame(1, DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->count());
        $this->assertSame($token, (string) DB::table('event_passes')
            ->where('commerce_order_item_id', $item->id)->value('token'));
        $this->assertSame(3, DB::table('ledger_entries')->where('payment_id', $payment->id)->count());
        $this->assertSame(2, DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->whereNotNull('processed_at')->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/payments/pay-asaas-p0'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_pagamento_tardio_apos_reclassificacao_curatorial_e_auditavel_sem_venda_qr_ou_ledger(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order, $item] = $this->persistedPaidCheckoutFixture();
        $payment->update(['provider_txid' => null]);

        // Checkout criado quando o cadastro era comercial. Antes do webhook,
        // a ficha e o ingresso deixam de ser comercializáveis.
        DB::table('events')->where('id', $order->event_id)->update([
            'listing_source' => 'curatorial',
            'ownership_status' => 'unclaimed',
            'verification_status' => 'unverified',
            'extra_info' => json_encode([
                'curation' => ['sale_mode' => 'information_only', 'ticket_checkout_enabled' => false],
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('tickets')->where('id', $item->ticket_id)->update(['curatorial_information_only' => true]);
        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));

        $service = app(AsaasCommerceWebhookService::class);
        $event = [
            'id' => 'evt-pagamento-curatorial-tardio-p0',
            'event' => 'PAYMENT_RECEIVED',
            'payment' => $this->paidAsaasPayload(),
        ];
        $service->process($event);

        $this->assertDatabaseHas('commerce_payments', [
            'id' => $payment->id, 'status' => 'paid',
        ]);
        $this->assertDatabaseHas('commerce_orders', [
            'id' => $order->id, 'status' => 'cancelled',
        ]);
        $metadata = json_decode((string) DB::table('commerce_orders')->where('id', $order->id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('blocked', $metadata['fulfillment_status'] ?? null);
        $this->assertSame('required', $metadata['financial_reconciliation_status'] ?? null);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseMissing('interactions', [
            'entity_type' => 'CommerceOrder',
            'entity_id' => $order->id,
            'interaction_type' => 'payment_paid',
        ]);
        $this->assertNotNull(DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', $event['id'])
            ->value('processed_at'));

        // Replays do mesmo webhook e de outro ID financeiro: mesmo status
        // bloqueado, sem emissão tardia mesmo se o anúncio voltar ao ar.
        $service->process($event);
        DB::table('events')->where('id', $order->event_id)->update([
            'listing_source' => 'legacy_organizer',
            'ownership_status' => null,
            'verification_status' => 'legacy_owner_record',
            'extra_info' => null,
        ]);
        DB::table('tickets')->where('id', $item->ticket_id)->update(['curatorial_information_only' => false]);
        $service->process(array_replace($event, ['id' => 'evt-pagamento-curatorial-replay-p0']));

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(2, DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->whereNotNull('processed_at')->count());
        Http::assertSentCount(2);
    }

    public function test_pagamento_tardio_de_lote_informativo_nao_emite_qr_nem_ledger(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order, $item] = $this->persistedPaidCheckoutFixture();
        $payment->update(['provider_txid' => null]);
        DB::table('tickets')->where('id', $item->ticket_id)
            ->update(['curatorial_information_only' => true]);
        Http::fake(fn () => Http::response($this->paidAsaasPayload(), 200));
        app(AsaasCommerceWebhookService::class)->process([
            'id' => 'evt-lote-informativo-tardio-p0',
            'event' => 'PAYMENT_RECEIVED',
            'payment' => $this->paidAsaasPayload(),
        ]);

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'paid']);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_payment_received_pix_get_divergente_nao_liquida_nem_reprocessa_recibo_pendente(): void
    {
        config()->set('services.asaas.api_key', 'chave-falsa-de-teste');
        \Illuminate\Support\Facades\Mail::fake();
        [$payment, $order] = $this->persistedPaidCheckoutFixture();
        $payment->update(['provider_txid' => null]);
        $remote = array_replace($this->paidAsaasPayload(), [
            'externalReference' => 'pedido-de-outra-aplicacao',
        ]);
        Http::fake(function () use (&$remote) {
            return Http::response($remote, 200);
        });

        $service = app(AsaasCommerceWebhookService::class);
        $event = [
            'id' => 'evt-payment-pix-remoto-divergente-p0',
            'event' => 'PAYMENT_RECEIVED',
            'payment' => $this->paidAsaasPayload(),
        ];
        $this->assertRejected(fn () => $service->process($event));

        $this->assertDatabaseHas('commerce_orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('commerce_payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas', 'event_id' => $event['id'], 'processed_at' => null,
        ]);
        Http::assertSentCount(1);

        // Corrigir o mock remoto não autoriza reprocessar um recibo pendente.
        $remote = $this->paidAsaasPayload();
        $service->process($event);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('event_passes', 0);
        $this->assertDatabaseHas('financial_webhook_events', [
            'provider' => 'asaas', 'event_id' => $event['id'], 'processed_at' => null,
        ]);
    }

    /**
     * Fixture persistida exclusivamente para testar liquidação de pedido já
     * existente. Não concede proveniência comercial nem altera a policy.
     */
    private function persistedPaidCheckoutFixture(): array
    {
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if ($appId <= 0) {
            $appId = DB::table('applications')->insertGetId([
                'name' => 'Cutinapp Teste', 'slug' => 'cutinapp', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $buyer = \App\Models\User::create([
            'first_name' => 'Comprador',
            'last_name' => 'Teste',
            'email' => 'comprador-asaas-e2e@cutinapp.test',
            'user_name' => 'comprador-asaas-e2e',
            'password' => \Illuminate\Support\Facades\Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $productionId = DB::table('establishments')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'name' => 'Produção E2E Asaas',
            'fantasy' => 'Produção E2E Asaas',
            'slug' => 'producao-e2e-asaas',
            'category' => 'production',
            'type' => 'production',
            'establishment_type' => 'production',
            'city' => 'São Paulo',
            'user_id' => $buyer->id,
            'created_by' => $buyer->id,
            'updated_by' => $buyer->id,
            'ownership_status' => null, 'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId, 'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento E2E Asaas',
            'created_by_user_id' => $buyer->id,
            'ownership_status' => null, 'listing_source' => 'legacy_organizer',
            'verification_status' => 'legacy_owner_record',
            'slug' => 'evento-asaas-e2e-p0',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(4),
            'is_published' => true, 'is_private' => false, 'is_cancelled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ticketId = DB::table('tickets')->insertGetId([
            'app_id' => $appId, 'app_slug' => 'cutinapp',
            'event_id' => $eventId, 'name' => 'Inteira E2E',
            'quantity' => 10, 'price' => 20.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = CommerceOrder::create([
            'app_id' => $appId, 'public_id' => 'pedido-asaas-p0',
            'event_id' => $eventId, 'production_id' => $productionId,
            'user_id' => $buyer->id, 'status' => 'pending', 'currency' => 'BRL',
            'subtotal' => 20.00, 'platform_fee' => 2.00, 'processor_fee' => 0,
            'discount_amount' => 0, 'total' => 20.00, 'producer_net' => 18.00,
            'payment_method' => 'pix', 'metadata' => ['settlement_mode' => 'platform_collection'],
        ]);
        $item = \App\Models\CommerceOrderItem::create([
            'app_id' => $appId, 'order_id' => $order->id,
            'type' => 'ticket', 'ticket_id' => $ticketId,
            'name' => 'Inteira E2E', 'unit_price' => 20.00,
            'quantity' => 1, 'subtotal' => 20.00,
        ]);
        $payment = CommercePayment::create([
            'app_id' => $appId, 'order_id' => $order->id,
            'provider' => 'asaas', 'method' => 'pix', 'status' => 'pending',
            'provider_payment_id' => 'pay-asaas-p0',
            'provider_txid' => 'checkout-asaas-p0',
            'idempotency_key' => 'asaas-e2e-p0',
            'amount' => 20.00, 'provider_fee' => 0,
        ]);

        return [$payment, $order, $item];
    }

    private function verifyCheckoutPaid(CommercePayment $payment, CommerceOrder $order, array $checkout): array
    {
        $method = new \ReflectionMethod(AsaasCommerceWebhookService::class, 'verifiedPaidCheckoutPayload');

        return $method->invoke(app(AsaasCommerceWebhookService::class), $payment, $order, $checkout);
    }

    private function paidCheckoutPayload(): array
    {
        return [
            'id' => 'checkout-asaas-p0',
            'externalReference' => 'pedido-asaas-p0',
            'status' => 'PAID',
            'items' => [['value' => 20.00, 'quantity' => 1]],
        ];
    }

    private function verifyPaid(CommercePayment $payment, CommerceOrder $order, array $notification): array
    {
        $method = new \ReflectionMethod(AsaasCommerceWebhookService::class, 'verifiedPaidPayload');

        return $method->invoke(app(AsaasCommerceWebhookService::class), $payment, $order, $notification);
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Confirmação sem identidade financeira completa deveria ser bloqueada.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function localPaymentPair(): array
    {
        $order = new CommerceOrder();
        $order->forceFill([
            'id' => 101,
            'app_id' => 7,
            'public_id' => 'pedido-asaas-p0',
            'total' => 20.00,
            'currency' => 'BRL',
            'payment_method' => 'pix',
        ]);

        $payment = new CommercePayment();
        $payment->forceFill([
            'id' => 202,
            'app_id' => 7,
            'order_id' => 101,
            'provider' => 'asaas',
            'provider_payment_id' => 'pay-asaas-p0',
            'method' => 'pix',
            'amount' => 20.00,
        ]);

        return [$payment, $order];
    }

    private function paidAsaasPayload(): array
    {
        return [
            'id' => 'pay-asaas-p0',
            'externalReference' => 'pedido-asaas-p0',
            'value' => 20.00,
            'billingType' => 'PIX',
            'status' => 'RECEIVED',
        ];
    }

}
