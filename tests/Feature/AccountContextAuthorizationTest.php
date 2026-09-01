<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountContextAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_account_context_does_not_grant_application_access(): void
    {
        $profile = Profile::create([
            'name' => 'Cliente',
            'permissions' => [],
        ]);

        $user = User::create([
            'first_name' => 'Conta',
            'email' => 'conta-contexto@example.test',
            'user_name' => 'conta-contexto',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        $application = Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'is_active' => true,
        ]);

        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/account/context?app_id=' . $application->id)
            ->assertOk()
            ->assertJsonPath('app_id', $application->id);

        $this->assertDatabaseMissing('application_user', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);
    }
}
