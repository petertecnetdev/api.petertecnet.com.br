<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Commerce\CommerceFulfillmentService;
use Tests\TestCase;

class CommerceFulfillmentServiceTest extends TestCase
{
    public function test_claim_is_hidden_until_order_is_ready(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order(['fulfillment_status' => CommerceFulfillmentService::PREPARING]);

        $this->assertNull($service->claimPayload($order));

        $order->fulfillment_status = CommerceFulfillmentService::READY;
        $claim = $service->claimPayload($order);

        $this->assertIsArray($claim);
        $this->assertTrue($claim['single_use']);
        $this->assertMatchesRegularExpression('/^RT-\d{3}-\d{3}$/', $claim['code']);
    }

    public function test_qr_token_contract_remains_compatible_with_existing_orders(): void
    {
        $service = app(CommerceFulfillmentService::class);
        $order = $this->order();

        $expected = hash_hmac(
            'sha256',
            implode('|', [$order->public_id, $order->id, $order->app_id, $order->client_id]),
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

    public function test_legacy_available_status_remains_redeemable_during_migration(): void
    {
        $service = app(CommerceFulfillmentService::class);

        $this->assertTrue($service->isReady(CommerceFulfillmentService::LEGACY_READY));
        $this->assertTrue($service->isTerminal(CommerceFulfillmentService::FULFILLED));
        $this->assertTrue($service->isTerminal(CommerceFulfillmentService::DELIVERED));
    }

    private function order(array $overrides = []): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'id' => 42,
            'public_id' => '11111111-2222-4333-8444-555555555555',
            'app_id' => 2,
            'client_id' => 77,
            'entity_id' => 12,
            'payment_status' => 'paid',
            'fulfillment_status' => CommerceFulfillmentService::READY,
            'fulfillment' => 'pickup',
            'order_number' => '123',
        ], $overrides));

        return $order;
    }
}
