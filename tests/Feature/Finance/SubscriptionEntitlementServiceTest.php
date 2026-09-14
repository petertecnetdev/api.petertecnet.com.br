<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\SubscriptionEntitlementService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionEntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_entitlements_are_only_active_inside_their_validity_window(): void
    {
        $user = User::query()->create([
            'first_name' => 'Entitlement',
            'last_name' => 'Tester',
            'email' => Str::uuid().'@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'auth_version' => 1,
        ]);

        $appId = DB::table('applications')->insertGetId([
            'name' => 'Entitlement Test App',
            'slug' => 'entitlement-test-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionId = DB::table('ecosystem_subscriptions')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $appId,
            'user_id' => $user->getKey(),
            'plan_code' => 'pro',
            'status' => 'active',
            'currency' => 'BRL',
            'price_cents' => 6990,
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ecosystem_entitlements')->insert([
            'app_id' => $appId,
            'user_id' => $user->getKey(),
            'subscription_id' => $subscriptionId,
            'key' => 'application_access',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'metadata' => json_encode(['value' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(SubscriptionEntitlementService::class);
        $this->assertNotEmpty($service->activeForSubscription($subscriptionId));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscriptionId)
            ->update([
                'starts_at' => now()->subMonth(),
                'expires_at' => now()->subSecond(),
            ]);

        $this->assertSame([], $service->activeForSubscription($subscriptionId));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscriptionId)
            ->update([
                'starts_at' => now()->addMinute(),
                'expires_at' => now()->addMonth(),
            ]);

        $this->assertSame([], $service->activeForSubscription($subscriptionId));

        DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscriptionId)
            ->update([
                'starts_at' => null,
                'expires_at' => null,
            ]);

        $this->assertNotEmpty($service->activeForSubscription($subscriptionId));
    }
}
