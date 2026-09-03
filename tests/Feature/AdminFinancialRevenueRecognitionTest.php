<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFinancialRevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_dashboard_recognizes_only_confirmed_payments_as_cash(): void
    {
        $token = $this->adminToken();
        $today = now()->startOfDay();

        $this->insertPayment('pending-qr', 'pending', 100, 10, 2, 88, [
            'created_at' => $today->copy()->addHours(8),
            'updated_at' => $today->copy()->addHours(8),
        ]);

        $this->insertPayment('paid-later', 'paid', 200, 20, 4, 176, [
            'created_at' => $today->copy()->subDays(2)->addHours(10),
            'updated_at' => $today->copy()->addHours(9),
            'paid_at' => $today->copy()->addHours(9),
        ]);

        $this->insertPayment('approved-card', 'approved', 50, 5, 1, 44, [
            'created_at' => $today->copy()->addHours(9),
            'updated_at' => $today->copy()->addHours(10),
            'paid_at' => $today->copy()->addHours(10),
        ]);

        $this->insertPayment('refunded-sale', 'refunded', 80, 8, 2, 70, [
            'created_at' => $today->copy()->subDay()->addHours(10),
            'updated_at' => $today->copy()->addHours(11),
            'paid_at' => $today->copy()->subDay()->addHours(10),
            'refunded_at' => $today->copy()->addHours(11),
        ]);

        $date = $today->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/ecosystem/financial/dashboard?from={$date}&to={$date}");

        $response
            ->assertOk()
            ->assertJsonPath('summary.totals.transactions', 4)
            ->assertJsonPath('summary.totals.gross', 250)
            ->assertJsonPath('summary.totals.platform_fees', 25)
            ->assertJsonPath('summary.totals.provider_fees', 5)
            ->assertJsonPath('summary.totals.seller_net', 220)
            ->assertJsonPath('summary.totals.attempted_gross', 430)
            ->assertJsonPath('summary.totals.open_gross', 100)
            ->assertJsonPath('summary.totals.reversed_gross', 80)
            ->assertJsonPath('summary.approved.count', 2)
            ->assertJsonPath('summary.approved.amount', 250)
            ->assertJsonPath('summary.pending.count', 1)
            ->assertJsonPath('summary.pending.amount', 100)
            ->assertJsonPath('summary.refunded.count', 1)
            ->assertJsonPath('summary.refunded.amount', 80)
            ->assertJsonPath('reconciliation.realized.amount', 250)
            ->assertJsonPath('reconciliation.open.amount', 100)
            ->assertJsonPath('reconciliation.reversed.amount', 80);

        $timeline = collect($response->json('timeline'));
        $todayBucket = $timeline->firstWhere('day', $date);

        $this->assertNotNull($todayBucket);
        $this->assertSame(250.0, (float) $todayBucket['gross']);
        $this->assertSame(430.0, (float) $todayBucket['attempted_gross']);
        $this->assertSame(100.0, (float) $todayBucket['pending_gross']);
    }

    public function test_pending_qr_charge_never_becomes_revenue_before_confirmation(): void
    {
        $token = $this->adminToken();

        $this->insertPayment('only-pending', 'pending', 399.90, 39.99, 7.50, 352.41);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/ecosystem/financial/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('summary.totals.transactions', 1)
            ->assertJsonPath('summary.totals.gross', 0)
            ->assertJsonPath('summary.totals.platform_fees', 0)
            ->assertJsonPath('summary.totals.provider_fees', 0)
            ->assertJsonPath('summary.totals.seller_net', 0)
            ->assertJsonPath('summary.totals.attempted_gross', 399.90)
            ->assertJsonPath('summary.totals.open_gross', 399.90)
            ->assertJsonPath('summary.pending.count', 1)
            ->assertJsonPath('summary.pending.amount', 399.90)
            ->assertJsonPath('reconciliation.realized.count', 0)
            ->assertJsonPath('reconciliation.open.count', 1);
    }

    private function adminToken(): string
    {
        $profile = Profile::firstOrCreate([
            'name' => 'Administrador',
        ], [
            'permissions' => [],
        ]);

        $administrator = User::create([
            'first_name' => 'Admin',
            'email' => 'admin-financial-' . Str::random(8) . '@example.test',
            'user_name' => 'admin-financial-' . Str::random(8),
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        return auth('api')->login($administrator);
    }

    private function insertPayment(
        string $reference,
        string $status,
        float $gross,
        float $platformFee,
        float $providerFee,
        float $sellerNet,
        array $dates = []
    ): void {
        DB::table('ecosystem_payments')->insert([
            'public_id' => (string) Str::uuid(),
            'app_id' => null,
            'app_slug' => 'nexus-financial-test',
            'provider' => 'mercadopago',
            'provider_payment_id' => 'provider-' . $reference,
            'source_type' => 'order',
            'source_reference' => $reference,
            'source_id' => null,
            'user_id' => null,
            'production_id' => null,
            'establishment_id' => null,
            'currency' => 'BRL',
            'method' => 'pix',
            'status' => $status,
            'gross_amount' => $gross,
            'platform_fee' => $platformFee,
            'provider_fee' => $providerFee,
            'seller_net' => $sellerNet,
            'metadata' => null,
            'paid_at' => $dates['paid_at'] ?? null,
            'refunded_at' => $dates['refunded_at'] ?? null,
            'failed_at' => $dates['failed_at'] ?? null,
            'created_at' => $dates['created_at'] ?? now(),
            'updated_at' => $dates['updated_at'] ?? now(),
        ]);
    }
}
