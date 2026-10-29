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

        $service->process([
            'id' => 'evt-replay-p0',
            'event' => 'EVENTO_DESCONHECIDO',
            'dateCreated' => '2026-10-29T19:06:00-03:00',
        ]);

        $this->assertDatabaseCount('financial_webhook_events', 1);
        $this->assertSame('{"marcador":"imutavel"}', DB::table('financial_webhook_events')
            ->where('provider', 'asaas')->where('event_id', 'evt-replay-p0')
            ->value('payload'));
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
