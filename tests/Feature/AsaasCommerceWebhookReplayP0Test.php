<?php

namespace Tests\Feature;

use App\Services\AsaasCommerceWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
