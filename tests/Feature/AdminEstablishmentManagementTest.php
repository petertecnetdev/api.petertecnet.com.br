<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminEstablishmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_update_an_establishment_owned_by_another_user(): void
    {
        $adminProfile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);
        $customerProfile = Profile::create([
            'name' => 'Cliente',
            'permissions' => [],
        ]);

        $administrator = User::create([
            'first_name' => 'Admin',
            'email' => 'admin-establishment@example.test',
            'user_name' => 'admin-establishment',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $adminProfile->id,
        ]);
        $owner = User::create([
            'first_name' => 'Owner',
            'email' => 'owner-establishment@example.test',
            'user_name' => 'owner-establishment',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $customerProfile->id,
        ]);

        $application = Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus-admin-establishment-test',
            'is_active' => true,
        ]);

        $establishment = Establishment::create([
            'name' => 'Empresa Original',
            'fantasy' => 'Original',
            'slug' => 'empresa-original-admin-test',
            'user_id' => $owner->id,
            'app_id' => $application->id,
            'city' => 'Belo Horizonte',
            'uf' => 'MG',
            'is_published' => false,
            'is_approved' => false,
        ]);

        $token = auth('api')->login($administrator);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/ecosystem/establishments/' . $establishment->id, [
                'name' => 'Empresa Atualizada pelo Admin',
                'fantasy' => 'Empresa Atualizada',
                'phone' => '(31) 99999-9999',
                'email' => 'contato-atualizado@example.test',
                'description' => 'Dados administrativos atualizados pelo Admin Center.',
                'city' => 'Contagem',
                'uf' => 'MG',
                'user_id' => $owner->id,
                'app_id' => $application->id,
                'app_ids' => [$application->id],
                'is_published' => true,
                'is_approved' => true,
                'is_featured' => true,
                'is_cancelled' => false,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('establishment.id', $establishment->id)
            ->assertJsonPath('establishment.name', 'Empresa Atualizada pelo Admin')
            ->assertJsonPath('establishment.user_id', $owner->id)
            ->assertJsonPath('establishment.is_approved', true);

        $this->assertDatabaseHas('establishments', [
            'id' => $establishment->id,
            'user_id' => $owner->id,
            'name' => 'Empresa Atualizada pelo Admin',
            'fantasy' => 'Empresa Atualizada',
            'city' => 'Contagem',
            'uf' => 'MG',
            'is_published' => 1,
            'is_approved' => 1,
            'is_featured' => 1,
            'updated_by' => $administrator->id,
        ]);

        $this->assertDatabaseHas('application_establishment', [
            'application_id' => $application->id,
            'establishment_id' => $establishment->id,
        ]);
    }
}
