<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EcosystemAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_ecosystem_lists_products_and_marks_only_authorized_ones_as_accessible(): void
    {
        $profile = Profile::create([
            'name' => 'Usuário',
            'permissions' => [],
        ]);

        $user = User::create([
            'first_name' => 'Conta',
            'email' => 'conta@example.test',
            'user_name' => 'conta-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        $rasoio = Application::create([
            'name' => 'Rasoio',
            'slug' => 'rasoio',
            'url' => 'https://rasoio.petertecnet.com.br',
            'is_active' => true,
        ]);

        Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'url' => 'https://nexus.petertecnet.com.br',
            'is_active' => true,
        ]);

        $user->applications()->attach($rasoio->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/account/ecosystem');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.email', 'conta@example.test')
            ->assertJsonFragment([
                'slug' => 'rasoio',
                'has_access' => true,
            ])
            ->assertJsonFragment([
                'slug' => 'nexus',
                'has_access' => false,
            ]);

        $this->assertCount(1, $response->json('data.accessible_applications'));
    }
}
