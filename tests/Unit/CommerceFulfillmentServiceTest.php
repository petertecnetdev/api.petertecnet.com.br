<?php

namespace Tests\Unit;

use App\Domain\Commerce\Services\CommerceFulfillmentService;
use App\Models\Order;
use Tests\TestCase;

class CommerceFulfillmentServiceTest extends TestCase
{
    public function test_claim_is_hidden_until_paid_order_is_ready(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order(['status' => CommerceFulfillmentService::PREPARING]);

        $this->assertNull($service->claimPayload($order));

        $order->status = CommerceFulfillmentService::READY;
        $claim = $service->claimPayload($order);

        $this->assertIsArray($claim);
        $this->assertSame(42, $claim['order_id']);
        $this->assertSame('42', $claim['public_id']);
        $this->assertTrue($claim['single_use']);
        $this->assertMatchesRegularExpression('/^RT-\d{3}-\d{3}$/', $claim['code']);
    }

    public function test_claim_is_hidden_while_payment_is_pending(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order(['payment_status' => 'pending']);

        $this->assertNull($service->claimPayload($order));
    }

    public function test_qr_token_is_deterministic_for_the_canonical_numeric_order_contract(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order();

        $expected = hash_hmac(
            'sha256',
            implode('|', ['commerce-fulfillment', $order->id, $order->app_id, $order->client_id, $order->order_number]),
            (string) config('app.key')
        );

        $this->assertSame($expected, $service->claimToken($order));
        $this->assertSame('qr_token', $service->credentialType($order, $expected, null));
    }

    public function test_manual_code_accepts_human_friendly_formatting(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order();
        $code = $service->claimCode($order);

        $this->assertSame('manual_code', $service->credentialType($order, null, strtolower($code)));
    }

    public function test_completed_orders_are_presented_as_fulfilled_or_delivered(): void
    {
        $service = app(CommerceFulfillmentService::class);

        $pickup = $this->order(['status' => 'completed', 'fulfillment' => 'pickup']);
        $delivery = $this->order(['status' => 'completed', 'fulfillment' => 'delivery']);

        $this->assertSame(CommerceFulfillmentService::FULFILLED, $service->presentedStatus($pickup));
        $this->assertSame(CommerceFulfillmentService::DELIVERED, $service->presentedStatus($delivery));
        $this->assertTrue($service->isTerminal($pickup));
        $this->assertTrue($service->isTerminal($delivery));
    }

    private function order(array $overrides = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'id' => 42,
            'app_id' => 2,
            'client_id' => 77,
            'entity_id' => 12,
            'payment_status' => 'paid',
            'status' => CommerceFulfillmentService::READY,
            'fulfillment' => 'pickup',
            'order_number' => '123',
        ], $overrides));

        return $order;
    }
}
