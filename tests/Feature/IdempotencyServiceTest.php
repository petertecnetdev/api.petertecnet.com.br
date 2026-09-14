<?php

namespace Tests\Feature;

use App\Support\Idempotency\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_request_replays_completed_response_without_reclaiming_operation(): void
    {
        $service = app(IdempotencyService::class);
        $payload = ['payment_method' => 'pix', 'items' => [['item_id' => 10, 'quantity' => 1]]];

        $first = $service->claim('checkout-key-123', 'app:9', 'guest', 'commerce.checkout', $payload);
        $this->assertSame('claimed', $first['state']);

        $service->complete($first['id'], response()->json([
            'success' => true,
            'data' => ['order' => ['id' => 321]],
        ], 201));

        $second = $service->claim('checkout-key-123', 'app:9', 'guest', 'commerce.checkout', $payload);
        $this->assertSame('replay', $second['state']);

        $response = $service->replay($second['record']);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('Idempotency-Replayed'));
        $this->assertSame(321, $response->getData(true)['data']['order']['id']);
        $this->assertDatabaseCount('idempotent_requests', 1);
    }

    public function test_same_key_with_changed_payload_is_rejected_as_conflict(): void
    {
        $service = app(IdempotencyService::class);

        $first = $service->claim(
            'checkout-key-456',
            'app:9',
            'guest',
            'commerce.checkout',
            ['items' => [['item_id' => 10, 'quantity' => 1]]],
        );
        $this->assertSame('claimed', $first['state']);

        $second = $service->claim(
            'checkout-key-456',
            'app:9',
            'guest',
            'commerce.checkout',
            ['items' => [['item_id' => 10, 'quantity' => 2]]],
        );

        $this->assertSame('conflict', $second['state']);
        $this->assertDatabaseCount('idempotent_requests', 1);
    }

    public function test_in_flight_duplicate_is_reported_as_processing(): void
    {
        $service = app(IdempotencyService::class);
        $payload = ['items' => [['item_id' => 10, 'quantity' => 1]]];

        $first = $service->claim('checkout-key-789', 'app:9', 'guest', 'commerce.checkout', $payload);
        $second = $service->claim('checkout-key-789', 'app:9', 'guest', 'commerce.checkout', $payload);

        $this->assertSame('claimed', $first['state']);
        $this->assertSame('processing', $second['state']);
        $this->assertDatabaseCount('idempotent_requests', 1);
    }

    public function test_same_key_is_isolated_by_application_scope(): void
    {
        $service = app(IdempotencyService::class);
        $payload = ['items' => [['item_id' => 10, 'quantity' => 1]]];

        $first = $service->claim('checkout-key-scope', 'app:9', 'guest', 'commerce.checkout', $payload);
        $second = $service->claim('checkout-key-scope', 'app:10', 'guest', 'commerce.checkout', $payload);

        $this->assertSame('claimed', $first['state']);
        $this->assertSame('claimed', $second['state']);
        $this->assertDatabaseCount('idempotent_requests', 2);
    }
}
