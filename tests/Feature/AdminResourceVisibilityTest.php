<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminResourceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_inspect_visibility_states_across_applications(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);
        $admin = User::create([
            'first_name' => 'Admin',
            'email' => 'visibility-admin@example.test',
            'user_name' => 'visibility-admin',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $app = $this->applicationFixture('visibility-app', [
            'name' => 'Visibility App',
            'is_active' => true,
        ]);

        DB::table('establishments')->insert([
            [
                'app_id' => $app->id,
                'name' => 'Empresa pública',
                'slug' => 'empresa-publica',
                'user_id' => $admin->id,
                'is_published' => true,
                'is_approved' => true,
                'is_cancelled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'app_id' => $app->id,
                'name' => 'Empresa oculta',
                'slug' => 'empresa-oculta',
                'user_id' => $admin->id,
                'is_published' => false,
                'is_approved' => true,
                'is_cancelled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $token = auth('api')->login($admin);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/ecosystem/visibility')
            ->assertOk()
            ->assertJsonPath('summary.public', 1)
            ->assertJsonPath('summary.hidden', 1)
            ->assertJsonCount(2, 'establishments');
    }
}
