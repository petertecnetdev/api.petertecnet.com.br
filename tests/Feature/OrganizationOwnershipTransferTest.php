<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_transfer_organization_and_loses_management_access_afterward(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Owner', 'owner-transfer@cutinapp.test');
        $newOwner = $this->user('New Owner', 'new-owner-transfer@cutinapp.test');
        $organization = $this->organization($app, $owner);

        $this->withHeaders($this->headersFor($owner))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", [
                'owner_user_id' => $newOwner->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Responsabilidade pela organização transferida com sucesso.')
            ->assertJsonPath('organization.user_id', $newOwner->id)
            ->assertJsonPath('owner.id', $newOwner->id);

        $this->assertDatabaseHas('establishments', [
            'id' => $organization->id,
            'user_id' => $newOwner->id,
            'category' => 'production',
        ]);
        $this->assertDatabaseHas('application_user', [
            'application_id' => $app->id,
            'user_id' => $newOwner->id,
            'role' => 'producer',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('interactions', [
            'entity_id' => $organization->id,
            'interaction_type' => 'update',
            'user_id' => $owner->id,
        ]);

        $this->withHeaders($this->headersFor($owner))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", ['name' => 'Tentativa do antigo dono'])
            ->assertForbidden();

        $this->withHeaders($this->headersFor($newOwner))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", ['name' => 'Nome pelo novo dono'])
            ->assertOk()
            ->assertJsonPath('organization.name', 'Nome pelo novo dono');
    }

    public function test_non_owner_cannot_transfer_organization(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Owner', 'owner-denied@cutinapp.test');
        $intruder = $this->user('Intruder', 'intruder-transfer@cutinapp.test');
        $target = $this->user('Target', 'target-transfer@cutinapp.test');
        $organization = $this->organization($app, $owner);

        $this->withHeaders($this->headersFor($intruder))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", [
                'owner_user_id' => $target->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('establishments', [
            'id' => $organization->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_owner_cannot_transfer_to_current_owner_or_mix_transfer_with_other_edits(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Owner', 'owner-validation@cutinapp.test');
        $target = $this->user('Target', 'target-validation@cutinapp.test');
        $organization = $this->organization($app, $owner);

        $this->withHeaders($this->headersFor($owner))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", [
                'owner_user_id' => $owner->id,
            ])
            ->assertStatus(422);

        $this->withHeaders($this->headersFor($owner))
            ->patchJson("/api/v1/apps/cutinapp/organizations/{$organization->id}", [
                'owner_user_id' => $target->id,
                'name' => 'Mudança misturada',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('establishments', [
            'id' => $organization->id,
            'user_id' => $owner->id,
            'name' => 'Produção de Transferência',
        ]);
    }

    private function organization(Application $app, User $owner): Production
    {
        return Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Produção de Transferência',
            'slug' => 'producao-transferencia-' . $owner->id,
            'city' => 'Goiânia',
            'uf' => 'GO',
            'is_published' => true,
            'is_cancelled' => false,
        ]);
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
