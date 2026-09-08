<?php

namespace Tests\Feature;

use App\Services\AppNotificationService;
use App\Services\OutboundDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class OutboundDeliveryReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_dedupe_key_persists_exactly_one_notification(): void
    {
        Event::fake();
        $service = app(AppNotificationService::class);
        $payload = ['type'=>'ticket_purchase_confirmed','title'=>'Presença confirmada','message'=>'Pagamento aprovado.'];

        $first = $service->sendToUserOnce(7, 42, 'ticket-purchase-confirmed:order:99', $payload);
        $second = $service->sendToUserOnce(7, 42, 'ticket-purchase-confirmed:order:99', $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('app_notifications', 1);
        $this->assertDatabaseHas('app_notifications', [
            'app_id'=>7,
            'user_id'=>42,
            'dedupe_key'=>'ticket-purchase-confirmed:order:99',
        ]);
    }

    public function test_outbound_delivery_runs_only_once_after_success(): void
    {
        $service = app(OutboundDeliveryService::class);
        $calls = 0;

        $this->assertTrue($service->deliverOnce(7, 'mail', 'ticket-passes:order:99', function () use (&$calls) {
            $calls++;
        }));
        $this->assertFalse($service->deliverOnce(7, 'mail', 'ticket-passes:order:99', function () use (&$calls) {
            $calls++;
        }));

        $this->assertSame(1, $calls);
        $this->assertDatabaseHas('outbound_deliveries', [
            'app_id'=>7,
            'channel'=>'mail',
            'dedupe_key'=>'ticket-passes:order:99',
            'status'=>'delivered',
            'attempts'=>1,
        ]);
    }

    public function test_failed_delivery_can_retry_without_losing_failure_observability(): void
    {
        $service = app(OutboundDeliveryService::class);

        try {
            $service->deliverOnce(7, 'mail', 'ticket-passes:order:100', function () {
                throw new RuntimeException('provider temporarily unavailable');
            });
            $this->fail('The delivery failure should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider temporarily unavailable', $e->getMessage());
        }

        $this->assertDatabaseHas('outbound_deliveries', [
            'dedupe_key'=>'ticket-passes:order:100',
            'status'=>'failed',
            'attempts'=>1,
        ]);

        $this->assertTrue($service->deliverOnce(7, 'mail', 'ticket-passes:order:100', fn () => null));
        $this->assertDatabaseHas('outbound_deliveries', [
            'dedupe_key'=>'ticket-passes:order:100',
            'status'=>'delivered',
            'attempts'=>2,
        ]);
    }

    public function test_fresh_processing_lock_suppresses_concurrent_delivery(): void
    {
        DB::table('outbound_deliveries')->insert([
            'app_id'=>7,
            'channel'=>'mail',
            'dedupe_key'=>'ticket-passes:order:101',
            'status'=>'processing',
            'attempts'=>1,
            'locked_at'=>now(),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        $calls = 0;
        $result = app(OutboundDeliveryService::class)->deliverOnce(7, 'mail', 'ticket-passes:order:101', function () use (&$calls) {
            $calls++;
        });

        $this->assertFalse($result);
        $this->assertSame(0, $calls);
        $this->assertDatabaseHas('outbound_deliveries', [
            'dedupe_key'=>'ticket-passes:order:101',
            'status'=>'processing',
            'attempts'=>1,
        ]);
    }
}
