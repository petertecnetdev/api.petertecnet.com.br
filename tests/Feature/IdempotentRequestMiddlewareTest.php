<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotentRequestMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_json_response_is_replayed_without_running_operation_twice(): void
    {
        $middleware = new EnsureIdempotentRequest();
        $calls = 0;

        $first = $middleware->handle(
            $this->request(['event_id' => 10, 'payment_method' => 'pix']),
            function () use (&$calls) {
                $calls++;

                return response()->json(['order' => ['public_id' => 'order-1']], 201);
            }
        );

        $second = $middleware->handle(
            $this->request(['event_id' => 10, 'payment_method' => 'pix']),
            function () use (&$calls) {
                $calls++;

                return response()->json(['order' => ['public_id' => 'order-2']], 201);
            }
        );

        $this->assertSame(1, $calls);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame('created', $first->headers->get('Idempotency-Status'));
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, DB::table('idempotent_requests')->count());
    }

    public function test_same_key_with_different_checkout_data_is_rejected(): void
    {
        $middleware = new EnsureIdempotentRequest();

        $middleware->handle(
            $this->request(['event_id' => 10, 'payment_method' => 'pix']),
            fn () => response()->json(['ok' => true], 201)
        );

        $response = $middleware->handle(
            $this->request(['event_id' => 11, 'payment_method' => 'pix']),
            fn () => response()->json(['ok' => true], 201)
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('conflict', $response->headers->get('Idempotency-Status'));
    }

    public function test_card_token_rotation_does_not_turn_a_retry_into_a_second_charge(): void
    {
        $middleware = new EnsureIdempotentRequest();
        $calls = 0;

        $middleware->handle(
            $this->request([
                'event_id' => 10,
                'payment_method' => 'card',
                'card_token' => 'first-single-use-token',
                'installments' => 1,
            ]),
            function () use (&$calls) {
                $calls++;

                return response()->json(['payment' => ['id' => 'payment-1']], 201);
            }
        );

        $response = $middleware->handle(
            $this->request([
                'event_id' => 10,
                'payment_method' => 'card',
                'card_token' => 'rotated-single-use-token',
                'installments' => 1,
            ]),
            function () use (&$calls) {
                $calls++;

                return response()->json(['payment' => ['id' => 'payment-2']], 201);
            }
        );

        $this->assertSame(1, $calls);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('Idempotency-Replayed'));
    }

    private function request(array $payload): Request
    {
        $request = Request::create('/api/v1/apps/cutinapp/commerce/checkout', 'POST', $payload);
        $request->headers->set('Authorization', 'Bearer idempotency-test-token');
        $request->headers->set('Idempotency-Key', 'checkout-test-key-0001');

        return $request;
    }
}
