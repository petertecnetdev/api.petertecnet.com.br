<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SelfServiceRegistrationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_grant_access_to_restricted_application(): void
    {
        Mail::fake();

        $application = Application::create([
            'name' => 'Restricted App',
            'slug' => 'restricted-app',
            'is_active' => true,
            'self_service_access' => false,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Security Test',
            'email' => 'restricted-registration@example.test',
            'password' => 'Str0ng!Password',
            'app_id' => $application->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['app_id']);

        $this->assertDatabaseMissing('users', [
            'email' => 'restricted-registration@example.test',
        ]);
        $this->assertDatabaseMissing('application_user', [
            'application_id' => $application->id,
        ]);
    }

    public function test_public_registration_can_join_active_self_service_application(): void
    {
        Mail::fake();

        $application = Application::create([
            'name' => 'Self Service App',
            'slug' => 'self-service-app',
            'is_active' => true,
            'self_service_access' => true,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Allowed User',
            'email' => 'allowed-registration@example.test',
            'password' => 'Str0ng!Password',
            'app_id' => $application->id,
        ]);

        $response->assertCreated();

        $user = User::query()
            ->where('email', 'allowed-registration@example.test')
            ->firstOrFail();

        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_public_registration_cannot_join_inactive_application_even_if_self_service_enabled(): void
    {
        Mail::fake();

        $application = Application::create([
            'name' => 'Inactive Self Service App',
            'slug' => 'inactive-self-service-app',
            'is_active' => false,
            'self_service_access' => true,
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Inactive App User',
            'email' => 'inactive-registration@example.test',
            'password' => 'Str0ng!Password',
            'app_id' => $application->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['app_id']);

        $this->assertDatabaseMissing('users', [
            'email' => 'inactive-registration@example.test',
        ]);
    }
}
