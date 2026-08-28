<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JwtVersionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_revokes_existing_v1_token(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        $user = User::create([
            'first_name' => 'Security',
            'email' => 'security@example.test',
            'user_name' => 'security-user',
            'password' => Hash::make('OldPass123!'),
            'profile_id' => $profile->id,
        ]);

        Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'is_active' => true,
        ]);

        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/apps/nexus/me')
            ->assertOk();

        $user->forceFill(['password' => Hash::make('NewPass123!')])->save();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/apps/nexus/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'TOKEN_REVOKED');
    }
}
