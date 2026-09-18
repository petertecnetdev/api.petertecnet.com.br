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
        $service = app(PayoutIdempotencyService::class);
        $production = $this->production(41, 'cutinapp');
        $user = $this->user(7);

        $first = $service->claim($production, $user, 'payout-intent-001', 125.50);
        $second = $service->claim($production, $user, 'payout-intent-001', 125.50);

        $this->assertSame('acquired', $first['state']);
        $this->assertSame('processing', $second['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $service = app(PayoutIdempotencyService::class);
        $production = $this->production(41, 'cutinapp');
        $user = $this->user(7);

        $service->claim($production, $user, 'payout-intent-002', 125.50);
        $conflict = $service->claim($production, $user, 'payout-intent-002', 126.50);

        $this->assertSame('conflict', $conflict['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_same_key_is_isolated_by_application_and_source(): void
    {
        $service = app(PayoutIdempotencyService::class);
        $user = $this->user(7);

        $cutinapp = $service->claim($this->production(41, 'cutinapp'), $user, 'shared-client-key', 50.00);
        $nexus = $service->claim($this->production(41, 'nexus'), $user, 'shared-client-key', 50.00);
        $otherSource = $service->claim($this->production(42, 'cutinapp'), $user, 'shared-client-key', 50.00);

        $this->assertSame('acquired', $cutinapp['state']);
        $this->assertSame('acquired', $nexus['state']);
        $this->assertSame('acquired', $otherSource['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 3);
    }

    public function test_completed_intent_replays_original_payout_id_without_reacquiring(): void
    {
        $service = app(PayoutIdempotencyService::class);
        $production = $this->production(41, 'cutinapp');
        $user = $this->user(7);
        $key = 'payout-intent-completed-001';

        $this->assertSame('acquired', $service->claim($production, $user, $key, 75.00)['state']);
        $service->complete($production, $key, 991);

        $replay = $service->claim($production, $user, $key, 75.00);

        $this->assertSame('replay', $replay['state']);
        $this->assertSame(991, $replay['payout_id']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_validation_release_allows_same_intent_to_be_retried_without_duplicate_claim(): void
    {
        $service = app(PayoutIdempotencyService::class);
        $production = $this->production(41, 'cutinapp');
        $user = $this->user(7);
        $key = 'payout-intent-retryable-001';

        $this->assertSame('acquired', $service->claim($production, $user, $key, 25.00)['state']);
        $service->release($production, $key);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 0);

        $retry = $service->claim($production, $user, $key, 25.00);

        $this->assertSame('acquired', $retry['state']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    public function test_release_cannot_delete_completed_intent(): void
    {
        $service = app(PayoutIdempotencyService::class);
        $production = $this->production(41, 'cutinapp');
        $user = $this->user(7);
        $key = 'payout-intent-locked-001';

        $service->claim($production, $user, $key, 90.00);
        $service->complete($production, $key, 992);
        $service->release($production, $key);

        $replay = $service->claim($production, $user, $key, 90.00);
        $this->assertSame('replay', $replay['state']);
        $this->assertSame(992, $replay['payout_id']);
        $this->assertDatabaseCount('financial_payout_idempotency_keys', 1);
    }

    private function production(int $id, string $appSlug): Production
    {
        $production = new Production();
        $production->id = $id;
        $production->app_slug = $appSlug;

        return $production;
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;

        return $user;
    }
}
