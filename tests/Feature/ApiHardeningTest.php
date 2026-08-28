<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_errors_include_request_id_header_and_payload(): void
    {
        $response = $this->getJson('/api/route-that-does-not-exist');

        $response
            ->assertNotFound()
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('request_id')
        );
    }

    public function test_saving_configured_email_does_not_implicitly_promote_user(): void
    {
        config()->set('peter.admin_email', 'admin@example.test');

        $basic = Profile::create([
            'name' => 'Usuário',
            'permissions' => [],
        ]);

        $admin = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        $user = User::create([
            'first_name' => 'Admin',
            'email' => 'admin@example.test',
            'user_name' => 'admin-not-promoted',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $basic->id,
        ]);

        $user->forceFill(['first_name' => 'Updated'])->save();

        $this->assertSame($basic->id, $user->fresh()->profile_id);
        $this->assertNotSame($admin->id, $user->fresh()->profile_id);
    }
}
