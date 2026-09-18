<?php

namespace Tests\Feature;

use App\Models\Production;
use App\Models\User;
use App\Services\PayoutIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutIdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_intent_is_claimed_only_once(): void
    {
        $service = app(PayoutIdempotencyService::class); $production = $this->production(41, 'cutinapp'); $user = $this->user(7);
        $this->assertSame('acquired', $service->claim($production, $user, 'payout-intent-001', 125.50)['state']);
        $this->assertSame('processing', $service->claim($production, $user, 'payout-intent-001', 125.50)['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $service = app(PayoutIdempotencyService::class); $production = $this->production(41, 'cutinapp'); $user = $this->user(7);
        $service->claim($production, $user, 'payout-intent-002', 125.50);
        $this->assertSame('conflict', $service->claim($production, $user, 'payout-intent-002', 126.50)['state']);
    }

    public function test_same_key_is_isolated_by_application_and_source(): void
    {
        $service = app(PayoutIdempotencyService::class); $user = $this->user(7);
        $this->assertSame('acquired', $service->claim($this->production(41, 'cutinapp'), $user, 'shared-client-key', 50)['state']);
        $this->assertSame('acquired', $service->claim($this->production(41, 'nexus'), $user, 'shared-client-key', 50)['state']);
        $this->assertSame('acquired', $service->claim($this->production(42, 'cutinapp'), $user, 'shared-client-key', 50)['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 3);
    }

    public function test_completed_intent_replays_without_reacquiring(): void
    {
        $service = app(PayoutIdempotencyService::class); $production = $this->production(41, 'cutinapp'); $user = $this->user(7); $key = 'payout-intent-completed-001';
        $service->claim($production, $user, $key, 75); $service->complete($production, $key, 991);
        $replay = $service->claim($production, $user, $key, 75);
        $this->assertSame('replay', $replay['state']); $this->assertSame(991, $replay['payout_id']); $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_release_allows_safe_retry_before_side_effect(): void
    {
        $service = app(PayoutIdempotencyService::class); $production = $this->production(41, 'cutinapp'); $user = $this->user(7); $key = 'payout-intent-retryable-001';
        $service->claim($production, $user, $key, 25); $service->release($production, $key);
        $this->assertSame('acquired', $service->claim($production, $user, $key, 25)['state']); $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_release_cannot_delete_completed_intent(): void
    {
        $service = app(PayoutIdempotencyService::class); $production = $this->production(41, 'cutinapp'); $user = $this->user(7); $key = 'payout-intent-locked-001';
        $service->claim($production, $user, $key, 90); $service->complete($production, $key, 992); $service->release($production, $key);
        $this->assertSame('replay', $service->claim($production, $user, $key, 90)['state']); $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    private function production(int $id, string $appSlug): Production { $p = new Production(); $p->id = $id; $p->app_slug = $appSlug; return $p; }
    private function user(int $id): User { $u = new User(); $u->id = $id; return $u; }
}
