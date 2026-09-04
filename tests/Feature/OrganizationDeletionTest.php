<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_soft_delete_organization_without_destroying_financial_history(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $user = User::create([
            'first_name' => 'Organization Owner',
            'email' => 'organization-owner@example.test',
            'user_name' => 'organization-owner',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $organization = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $user->id,
            'name' => 'Organização removível',
            'slug' => 'organizacao-removivel',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $payoutReference = null;
        if (Schema::hasTable('payout_requests')) {
            $payoutReference = 'organization-delete-test-'.$organization->id;
            DB::table('payout_requests')->insert([
                'production_id' => $organization->id,
                'requested_by_user_id' => $user->id,
                'reference' => $payoutReference,
                'provider' => 'test',
                'settlement_mode' => 'platform_collection',
                'amount' => 10,
                'status' => 'pending',
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $headers = [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/apps/cutinapp/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('message', 'Organização excluída com sucesso.');

        $this->assertSoftDeleted('productions', ['id' => $organization->id]);
        $this->assertNull(Production::query()->find($organization->id));
        $this->assertNotNull(Production::withTrashed()->find($organization->id));

        if ($payoutReference !== null) {
            $this->assertDatabaseHas('payout_requests', [
                'production_id' => $organization->id,
                'reference' => $payoutReference,
            ]);
        }

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/mine')
            ->assertOk()
            ->assertJsonCount(0, 'organizations');

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/'.$organization->id)
            ->assertNotFound();
    }

    public function test_user_cannot_delete_another_users_organization(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = User::create([
            'first_name' => 'Owner',
            'email' => 'organization-real-owner@example.test',
            'user_name' => 'organization-real-owner',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
        $otherUser = User::create([
            'first_name' => 'Other User',
            'email' => 'organization-other-user@example.test',
            'user_name' => 'organization-other-user',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $organization = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Organização protegida',
            'slug' => 'organizacao-protegida',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.JWTAuth::fromUser($otherUser),
            'Accept' => 'application/json',
        ])->deleteJson('/api/v1/apps/cutinapp/organizations/'.$organization->id)
            ->assertForbidden();

        $this->assertDatabaseHas('productions', [
            'id' => $organization->id,
            'deleted_at' => null,
        ]);
    }
}
