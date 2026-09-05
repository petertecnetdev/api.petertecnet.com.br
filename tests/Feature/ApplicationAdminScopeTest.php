<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApplicationAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_application_member_cannot_access_application_admin(): void
    {
        $app = $this->application('Cutinapp', 'cutinapp');
        $user = $this->user('member@example.test');
        $this->attach($user, $app, []);

        $this->actingAsApi($user)
            ->getJson('/api/v1/apps/cutinapp/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_REQUIRED');
    }

    public function test_application_admin_can_access_only_the_application_where_role_is_active(): void
    {
        $cutinapp = $this->application('Cutinapp', 'cutinapp');
        $nexus = $this->application('Nexus', 'nexus');
        $user = $this->user('cutinapp-admin@example.test');

        $this->attach($user, $cutinapp, ['application_admin']);
        $this->attach($user, $nexus, []);

        $this->actingAsApi($user)
            ->getJson('/api/v1/apps/cutinapp/admin/overview')
            ->assertOk()
            ->assertJsonPath('application.slug', 'cutinapp')
            ->assertJsonPath('access.role', 'application_admin')
            ->assertJsonPath('access.source', 'application_membership');

        $this->actingAsApi($user)
            ->getJson('/api/v1/apps/nexus/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_REQUIRED');
    }

    public function test_suspended_application_admin_cannot_access_application_admin(): void
    {
        $app = $this->application('Cutinapp', 'cutinapp');
        $user = $this->user('suspended-admin@example.test');
        $this->attach($user, $app, ['application_admin'], 'suspended');

        $this->actingAsApi($user)
            ->getJson('/api/v1/apps/cutinapp/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'APPLICATION_ADMIN_REQUIRED');
    }

    public function test_ecosystem_owner_can_access_application_admin_without_membership(): void
    {
        $this->application('Cutinapp', 'cutinapp');
        $owner = $this->user('petertecnet@gmail.com');

        $this->actingAsApi($owner)
            ->getJson('/api/v1/apps/cutinapp/admin/overview')
            ->assertOk()
            ->assertJsonPath('application.slug', 'cutinapp')
            ->assertJsonPath('access.source', 'ecosystem_owner');
    }

    private function actingAsApi(User $user): self
    {
        $token = auth('api')->login($user);
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function user(string $email): User
    {
        $profile = Profile::firstOrCreate(
            ['name' => 'Usuário'],
            ['permissions' => []]
        );

        return User::create([
            'first_name' => 'Test',
            'email' => $email,
            'user_name' => 'user-'.substr(hash('sha1', $email), 0, 10),
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return $this->applicationFixture($slug, [
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function attach(User $user, Application $application, array $roles, string $status = 'active'): void
    {
        $application->users()->attach($user->id, [
            'role' => null,
            'status' => $status,
            'metadata' => json_encode(['roles' => $roles]),
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
