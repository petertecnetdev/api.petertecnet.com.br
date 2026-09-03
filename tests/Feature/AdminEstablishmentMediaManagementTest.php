<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminEstablishmentMediaManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_manage_logo_and_background_for_another_users_establishment(): void
    {
        Storage::fake('public');

        $adminProfile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $clientProfile = Profile::create(['name' => 'Cliente', 'permissions' => []]);

        $admin = User::create([
            'first_name' => 'Admin',
            'email' => 'admin-media@example.test',
            'user_name' => 'admin-media',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $adminProfile->id,
        ]);

        $owner = User::create([
            'first_name' => 'Owner',
            'email' => 'owner-media@example.test',
            'user_name' => 'owner-media',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $clientProfile->id,
        ]);

        $application = Application::create([
            'name' => 'Media Test App',
            'slug' => 'media-test-app',
            'is_active' => true,
        ]);

        $establishment = Establishment::create([
            'name' => 'Estabelecimento Visual',
            'fantasy' => 'Estabelecimento Visual',
            'user_id' => $owner->id,
            'app_id' => $application->id,
            'is_published' => true,
            'is_approved' => true,
        ]);

        $token = auth('api')->login($admin);
        $authorization = ['Authorization' => 'Bearer ' . $token];

        $logoResponse = $this->withHeaders($authorization)->post('/api/file', [
            'app_id' => $application->id,
            'entity_id' => $establishment->id,
            'entity_name' => 'establishment',
            'group' => 'logo',
            'is_primary' => true,
            'position' => 0,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->image('logo.png', 512, 512),
        ]);

        $logoResponse
            ->assertCreated()
            ->assertJsonPath('file.entity_id', $establishment->id)
            ->assertJsonPath('file.entity_name', 'establishment')
            ->assertJsonPath('file.group', 'logo')
            ->assertJsonPath('file.is_primary', true);

        $backgroundResponse = $this->withHeaders($authorization)->post('/api/file', [
            'app_id' => $application->id,
            'entity_id' => $establishment->id,
            'entity_name' => 'establishment',
            'group' => 'background',
            'is_primary' => true,
            'position' => 1,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->image('background.jpg', 1600, 900),
        ]);

        $backgroundResponse
            ->assertCreated()
            ->assertJsonPath('file.group', 'background');

        $logoId = (int) $logoResponse->json('file.id');
        $backgroundId = (int) $backgroundResponse->json('file.id');

        $this->getJson('/api/file/list-by-entity?entity_id=' . $establishment->id . '&entity_name=establishment')
            ->assertOk()
            ->assertJsonFragment(['id' => $logoId, 'group' => 'logo'])
            ->assertJsonFragment(['id' => $backgroundId, 'group' => 'background']);

        $this->assertDatabaseHas('files', [
            'id' => $logoId,
            'entity_id' => $establishment->id,
            'entity_name' => 'establishment',
            'group' => 'logo',
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('files', [
            'id' => $backgroundId,
            'entity_id' => $establishment->id,
            'entity_name' => 'establishment',
            'group' => 'background',
            'created_by' => $admin->id,
        ]);

        $this->withHeaders($authorization)
            ->deleteJson('/api/file/' . $logoId)
            ->assertOk();

        $this->assertDatabaseMissing('files', ['id' => $logoId]);
        $this->assertDatabaseHas('files', ['id' => $backgroundId]);
    }
}
