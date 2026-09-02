<?php

namespace Tests\Feature;

use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PeterPlatformProductAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutinapp_legacy_contract_is_available_through_v1_without_deprecation_headers(): void
    {
        Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'is_active' => true,
        ]);

        $legacy = $this->getJson('/api/cutinapp/config');
        $legacy->assertOk();
        $legacy->assertHeader('X-Peter-API-Deprecated', 'true');

        $v1 = $this->getJson('/api/v1/apps/cutinapp/cutinapp/config');
        $v1->assertOk();
        $this->assertFalse($v1->headers->has('X-Peter-API-Deprecated'));
        $this->assertFalse($v1->headers->has('Deprecation'));
    }

    public function test_app_scoped_identity_registration_grants_application_access(): void
    {
        Mail::fake();

        $application = Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/apps/cutinapp/auth/register', [
            'first_name' => 'Pessoa Teste',
            'email' => 'identity-v1@example.test',
            'password' => 'StrongPass123!',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'identity-v1@example.test',
        ]);

        $userId = (int) \DB::table('users')->where('email', 'identity-v1@example.test')->value('id');
        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $userId,
            'status' => 'active',
        ]);
    }

    public function test_product_adapter_rejects_inactive_application_context(): void
    {
        Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/apps/cutinapp/cutinapp/config')
            ->assertNotFound()
            ->assertJsonPath('code', 'APPLICATION_NOT_AVAILABLE');
    }
}
