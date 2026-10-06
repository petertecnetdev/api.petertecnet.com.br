<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPrivateFileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_show_hides_private_files_from_another_user(): void
    {
        $owner = $this->user('owner@example.test', 'owner-private-files');
        $viewer = $this->user('viewer@example.test', 'viewer-private-files');

        File::create([
            'entity_name' => 'user',
            'entity_id' => $owner->id,
            'type' => 'identity',
            'original_name' => 'identity.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'storage' => 'local',
            'path' => 'private/identity.pdf',
            'storage_path' => '/tmp/private/identity.pdf',
            'public_url' => null,
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        File::create([
            'entity_name' => 'user',
            'entity_id' => $owner->id,
            'type' => 'avatar',
            'original_name' => 'avatar.png',
            'extension' => 'png',
            'mime_type' => 'image/png',
            'file_size' => 10,
            'storage' => 'public',
            'path' => 'uploads/user/avatar.png',
            'storage_path' => '/tmp/uploads/user/avatar.png',
            'public_url' => 'https://example.test/avatar.png',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $token = auth('api')->login($viewer);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/show/' . $owner->id)
            ->assertForbidden();
    }

    public function test_owner_can_see_own_private_files(): void
    {
        $owner = $this->user('owner-self@example.test', 'owner-self-private-files');

        File::create([
            'entity_name' => 'user',
            'entity_id' => $owner->id,
            'type' => 'identity',
            'original_name' => 'identity.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'storage' => 'local',
            'path' => 'private/identity.pdf',
            'storage_path' => '/tmp/private/identity.pdf',
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $token = auth('api')->login($owner);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user/show/' . $owner->id)
            ->assertOk()
            ->assertJsonCount(1, 'user.files')
            ->assertJsonPath('user.files.0.visibility', 'private');
    }

    private function user(string $email, string $userName): User
    {
        $profile = Profile::firstOrCreate(['name' => 'Cliente'], ['permissions' => []]);

        return User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'user_name' => $userName,
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }
}
