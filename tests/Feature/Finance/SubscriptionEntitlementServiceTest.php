<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\SubscriptionEntitlementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitlements_are_only_active_inside_their_validity_window(): void
    {
        config(['services.mercadopago.access_token' => 'test-platform-token']);

        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response([
                'id' => 'pix-approved-entitlement-window',
                'status' => 'approved',
                'status_detail' => 'accredited',
                'date_approved' => now()->toIso8601String(),
                'point_of_interaction' => [
                    'transaction_data' => ['qr_code' => 'approved-pix'],
                ],
            ], 201),
        ]);

        $user = User::query()->create([
            'first_name' => 'Entitlement',
            'last_name' => 'Tester',
            'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'auth_version' => 1,
        ]);
        $token = auth('api')->login($user);
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => (string) Str::uuid(),
        ];

        $intent = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/payflow/subscription-intents', ['plan_code' => 'pro'])
            ->assertCreated();

        $this->withHeaders([
            'Authorization' => $headers['Authorization'],
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson(
            '/api/v1/apps/payflow/subscription-intents/'.$intent->json('data.id').'/checkout',
            ['method' => 'pix']
        )->assertCreated();

        $subscription = DB::table('ecosystem_subscriptions')
            ->where('user_id', $user->getKey())
            ->first();

        $this->assertNotNull($subscription);

        $service = app(SubscriptionEntitlementService::class);
        $this->assertNotEmpty($service->activeForSubscription((int) $subscription->id));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscription->id)
            ->update([
                'starts_at' => now()->subMonth(),
                'expires_at' => now()->subSecond(),
            ]);

        $this->assertSame([], $service->activeForSubscription((int) $subscription->id));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscription->id)
            ->update([
                'starts_at' => now()->addMinute(),
                'expires_at' => now()->addMonth(),
            ]);

        $this->assertSame([], $service->activeForSubscription((int) $subscription->id));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscription->id)
            ->update([
                'starts_at' => null,
                'expires_at' => null,
            ]);

        $this->assertNotEmpty($service->activeForSubscription((int) $subscription->id));
    }
}
