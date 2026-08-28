<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiApplicationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_cannot_be_created_for_establishment_from_another_application(): void
    {
        $user = $this->adminUser();
        $rasoio = $this->application('Rasoio', 'rasoio');
        $nexus = $this->application('Nexus', 'nexus');

        $establishment = Establishment::create([
            'app_id' => $rasoio->id,
            'name' => 'Peter Tecnet Barbearia',
            'slug' => 'peter-tecnet-barbearia',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/item', [
                'app_id' => $nexus->id,
                'entity_name' => 'establishment',
                'entity_id' => $establishment->id,
                'name' => 'Corte masculino',
                'type' => 'service',
                'price' => 50,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNPROCESSABLE_ENTITY');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_item_can_be_created_when_application_matches_establishment(): void
    {
        $user = $this->adminUser();
        $nexus = $this->application('Nexus', 'nexus');

        $establishment = Establishment::create([
            'app_id' => $nexus->id,
            'name' => 'Catálogo Demo',
            'slug' => 'catalogo-demo',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/item', [
                'app_id' => $nexus->id,
                'entity_name' => 'establishment',
                'entity_id' => $establishment->id,
                'name' => 'Produto de demonstração',
                'type' => 'product',
                'price' => 99.90,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('items', [
            'app_id' => $nexus->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'name' => 'Produto de demonstração',
        ]);
    }

    public function test_establishment_application_cannot_be_changed_after_creation(): void
    {
        $user = $this->adminUser();
        $rasoio = $this->application('Rasoio', 'rasoio');
        $nexus = $this->application('Nexus', 'nexus');

        $establishment = Establishment::create([
            'app_id' => $rasoio->id,
            'name' => 'Empresa Isolada',
            'slug' => 'empresa-isolada',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/establishment/' . $establishment->id, [
                'app_id' => $nexus->id,
            ]);

        $response->assertStatus(409);
        $this->assertDatabaseHas('establishments', [
            'id' => $establishment->id,
            'app_id' => $rasoio->id,
        ]);
    }

    private function adminUser(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        return User::create([
            'first_name' => 'Admin',
            'email' => 'admin@example.test',
            'user_name' => 'admin-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return Application::create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }
}
