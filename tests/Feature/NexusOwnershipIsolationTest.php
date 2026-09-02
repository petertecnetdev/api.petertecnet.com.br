<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NexusOwnershipIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_create_item_inside_another_users_nexus_establishment(): void
    {
        [$app, $owner, $attacker, $establishment] = $this->fixture();

        $response = $this->asUser($attacker)->postJson('/api/item', [
            'app_id' => $app->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'name' => 'Tentativa indevida',
            'type' => 'product',
            'price' => 10,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('items', ['name' => 'Tentativa indevida']);
    }

    public function test_user_cannot_update_or_delete_another_users_nexus_establishment(): void
    {
        [, $owner, $attacker, $establishment] = $this->fixture();

        $this->asUser($attacker)
            ->putJson('/api/establishment/' . $establishment->id, ['name' => 'Invadida'])
            ->assertForbidden();

        $this->asUser($attacker)
            ->deleteJson('/api/establishment/' . $establishment->id)
            ->assertForbidden();

        $this->assertDatabaseHas('establishments', [
            'id' => $establishment->id,
            'name' => 'Catálogo do proprietário',
            'user_id' => $owner->id,
        ]);
    }

    public function test_user_cannot_update_or_delete_another_users_nexus_item(): void
    {
        [$app, $owner, $attacker, $establishment] = $this->fixture();

        $item = Item::create([
            'app_id' => $app->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'name' => 'Produto protegido',
            'slug' => 'produto-protegido',
            'type' => 'product',
            'price' => 42,
            'status' => true,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $this->asUser($attacker)
            ->putJson('/api/item/' . $item->id, ['name' => 'Produto invadido'])
            ->assertForbidden();

        $this->asUser($attacker)
            ->deleteJson('/api/item/' . $item->id)
            ->assertForbidden();

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'name' => 'Produto protegido',
            'user_id' => $owner->id,
        ]);
    }

    private function fixture(): array
    {
        $app = Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'is_active' => true,
        ]);

        $profile = Profile::create([
            'name' => 'Nexus Operator',
            'permissions' => ['item_create', 'item_edit', 'item_delete'],
        ]);

        $owner = $this->user('owner@nexus.test', 'owner-nexus', $profile);
        $attacker = $this->user('attacker@nexus.test', 'attacker-nexus', $profile);

        $establishment = Establishment::create([
            'app_id' => $app->id,
            'name' => 'Catálogo do proprietário',
            'slug' => 'catalogo-proprietario',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        return [$app, $owner, $attacker, $establishment];
    }

    private function user(string $email, string $username, Profile $profile): User
    {
        return User::create([
            'first_name' => 'Nexus',
            'email' => $email,
            'user_name' => $username,
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
            'profile_id' => $profile->id,
        ]);
    }

    private function asUser(User $user): self
    {
        $token = auth('api')->login($user);
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }
}
