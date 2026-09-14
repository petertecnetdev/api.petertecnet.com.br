<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestIdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_test/idempotent-guest', function (Request $request) {
            return response()->json([
                'nonce' => (string) Str::uuid(),
                'value' => $request->input('value'),
            ], 201);
        })->middleware(EnsureIdempotentRequest::class);
    }

    public function test_guest_retry_replays_the_original_response(): void
    {
        $headers = ['Idempotency-Key' => 'guest-checkout-123'];

        $first = $this->postJson('/_test/idempotent-guest', ['value' => 'same'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotency-Status', 'created');

        $second = $this->postJson('/_test/idempotent-guest', ['value' => 'same'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotency-Status', 'replayed')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('nonce'), $second->json('nonce'));
        $this->assertDatabaseHas('idempotent_requests', [
            'application_key' => 'global',
            'actor_key' => 'guest',
            'idempotency_key' => 'guest-checkout-123',
            'response_status' => 201,
        ]);
        $this->assertDatabaseCount('idempotent_requests', 1);
    }

    public function test_guest_cannot_reuse_the_same_key_with_different_payload(): void
    {
        $headers = ['Idempotency-Key' => 'guest-checkout-456'];

        $this->postJson('/_test/idempotent-guest', ['value' => 'first'], $headers)
            ->assertCreated();

        $this->postJson('/_test/idempotent-guest', ['value' => 'changed'], $headers)
            ->assertStatus(409)
            ->assertHeader('Idempotency-Status', 'conflict');

        $this->assertDatabaseCount('idempotent_requests', 1);
    }

    public function test_guest_duplicate_while_original_is_in_flight_is_retryable_without_running_again(): void
    {
        $headers = ['Idempotency-Key' => 'guest-checkout-789'];

        $this->postJson('/_test/idempotent-guest', ['value' => 'pending'], $headers)
            ->assertCreated();

        DB::table('idempotent_requests')
            ->where('idempotency_key', 'guest-checkout-789')
            ->update([
                'completed_at' => null,
                'response_status' => null,
                'response_body' => null,
            ]);

        $this->postJson('/_test/idempotent-guest', ['value' => 'pending'], $headers)
            ->assertStatus(425)
            ->assertHeader('Idempotency-Status', 'processing')
            ->assertHeader('Retry-After', '2');

        $this->assertDatabaseCount('idempotent_requests', 1);
    }
}
