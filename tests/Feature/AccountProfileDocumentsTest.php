<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountProfileDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_own_extended_profile(): void
    {
        $user = $this->user('perfil@example.test', 'perfil-user');
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/account/profile', [
                'first_name' => 'Maria',
                'last_name' => 'Silva',
                'cpf' => '12345678901',
                'phone' => '62999999999',
                'birthdate' => '1990-05-10',
                'occupation' => 'Arquiteta',
                'address' => 'Rua Um, 100',
                'city' => 'Goiânia',
                'uf' => 'go',
                'postal_code' => '74000000',
                'nationality' => 'Brasileira',
                'identity_document_type' => 'RG',
                'identity_document_number' => '1234567',
                'identity_document_issuer' => 'SSP/GO',
            ])
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Maria')
            ->assertJsonPath('user.uf', 'GO')
            ->assertJsonPath('user.nationality', 'Brasileira')
            ->assertJsonPath('completion.percentage', 100);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'cpf' => '12345678901', 'uf' => 'GO']);
        $this->assertSame('1234567', $user->fresh()->extra_info['account_profile']['identity_document_number']);
    }

    public function test_documents_are_private_and_scoped_to_the_owner(): void
    {
        Storage::fake('local');
        $owner = $this->user('owner@example.test', 'owner-doc');
        $other = $this->user('other@example.test', 'other-doc');
        $ownerToken = auth('api')->login($owner);

        $response = $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->post('/api/account/documents', [
                'category' => 'identity',
                'side' => 'front',
                'file' => UploadedFile::fake()->image('identidade.jpg', 900, 600)->size(200),
            ]);

        $response->assertCreated()->assertJsonPath('document.category', 'identity');
        $uuid = $response->json('document.uuid');
        $path = \App\Models\AccountDocument::where('uuid', $uuid)->value('storage_path');
        Storage::disk('local')->assertExists($path);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->getJson('/api/account/documents')
            ->assertOk()
            ->assertJsonCount(1, 'documents');

        $otherToken = auth('api')->login($other);
        $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->get('/api/account/documents/' . $uuid . '/download')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->deleteJson('/api/account/documents/' . $uuid)
            ->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_account_documents_require_authentication(): void
    {
        $this->getJson('/api/account/documents')->assertUnauthorized();
        $this->getJson('/api/account/profile')->assertUnauthorized();
    }

    private function user(string $email, string $userName): User
    {
        $profile = Profile::firstOrCreate(['name' => 'Cliente'], ['permissions' => []]);

        return User::create([
            'first_name' => 'Conta',
            'email' => $email,
            'user_name' => $userName,
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }
}
