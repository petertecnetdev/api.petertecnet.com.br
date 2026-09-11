<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionIntentTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'first_name' => 'Subscription',
            'last_name' => 'Tester',
            'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'auth_version' => 1,
        ]);
    }

    private function headers(User $user, string $idempotencyKey): array
    {
        $token = auth('api')->login($user);

        return [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => $idempotencyKey,
        ];
    }

    public function test_authenticated_user_can_create_idempotent_subscription_intent_with_server_price(): void
    {
        $user = $this->user();
        $key = (string) Str::uuid();
        $headers = $this->headers($user, $key);

        $payload = [
            'plan_code' => 'pro',
            'source' => 'subscription_plans',
            'handoff_channel' => 'whatsapp',
            'metadata' => ['client_price_cents' => 1],
        ];

        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/payflow/subscription-intents', $payload);

        $first->assertCreated()
            ->assertJsonPath('data.plan_code', 'pro')
            ->assertJsonPath('data.price_cents', 7990)
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.status', 'created');

        $firstId = $first->json('data.id');

        $second = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/payflow/subscription-intents', $payload);

        $second->assertSuccessful()
            ->assertJsonPath('data.id', $firstId);
        $this->assertSame(1, SubscriptionIntent::query()->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_another_plan(): void
    {
        $user = $this->user();
        $key = (string) Str::uuid();
        $headers = $this->headers($user, $key);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/payflow/subscription-intents', ['plan_code' => 'starter'])
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/payflow/subscription-intents', ['plan_code' => 'business'])
            ->assertStatus(409);
    }

    public function test_transaction_only_application_cannot_create_subscription_intent(): void
    {
        $user = $this->user();

        $this->withHeaders($this->headers($user, (string) Str::uuid()))
            ->postJson('/api/v1/apps/cutinapp/subscription-intents', ['plan_code' => 'pro'])
            ->assertStatus(422);
    }

    public function test_user_can_recover_latest_pending_intent_for_current_application(): void
    {
        $user = $this->user();
        $headers = $this->headers($user, (string) Str::uuid());

        SubscriptionIntent::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'application' => 'plat',
            'plan_code' => 'pro',
            'plan_name' => 'Pro',
            'price_cents' => 9990,
            'currency' => 'BRL',
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $recoverable = SubscriptionIntent::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'application' => 'plat',
            'plan_code' => 'business',
            'plan_name' => 'Business',
            'price_cents' => 19990,
            'currency' => 'BRL',
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
            'status' => 'payment_pending',
            'source' => 'subscription_plans',
            'metadata' => ['referral' => 'bar-do-peter'],
            'idempotency_key' => (string) Str::uuid(),
            'payment_pending_at' => now(),
        ]);

        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/apps/plat/subscription-intents/recoverable');

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $recoverable->public_id)
            ->assertJsonPath('data.application', 'plat')
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.metadata.referral', 'bar-do-peter');
        $response->assertJsonMissingPath('data.user_id');
        $response->assertJsonMissingPath('data.idempotency_key');
    }

    public function test_recovery_is_scoped_to_user_application_status_and_seven_day_window(): void
    {
        $user = $this->user();
        $otherUser = $this->user();
        $headers = $this->headers($user, (string) Str::uuid());

        $base = [
            'plan_code' => 'pro',
            'plan_name' => 'Pro',
            'price_cents' => 9990,
            'currency' => 'BRL',
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
        ];

        foreach ([
            ['user_id' => $otherUser->getKey(), 'application' => 'plat', 'status' => 'payment_pending', 'created_at' => now()],
            ['user_id' => $user->getKey(), 'application' => 'rasoio', 'status' => 'payment_pending', 'created_at' => now()],
            ['user_id' => $user->getKey(), 'application' => 'plat', 'status' => 'payment_failed', 'created_at' => now()],
            ['user_id' => $user->getKey(), 'application' => 'plat', 'status' => 'payment_pending', 'created_at' => now()->subDays(8)],
        ] as $candidate) {
            SubscriptionIntent::query()->create($base + $candidate + [
                'public_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'updated_at' => $candidate['created_at'],
            ]);
        }

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/plat/subscription-intents/recoverable')
            ->assertSuccessful()
            ->assertExactJson(['data' => null]);
    }
}
