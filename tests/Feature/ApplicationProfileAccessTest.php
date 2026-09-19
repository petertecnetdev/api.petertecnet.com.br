<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationProfile;
use App\Models\ApplicationProfileAssignment;
use App\Models\User;
use App\Services\ApplicationProfileAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_are_union_of_profiles_inside_the_requested_scope(): void
    {
        $application = Application::query()->create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'is_active' => true,
        ]);

        $producer = ApplicationProfile::query()->create([
            'application_id' => $application->id,
            'slug' => 'producer',
            'name' => 'Produtor',
            'permissions' => ['production.create', 'event.create'],
            'is_system' => true,
        ]);

        $artist = ApplicationProfile::query()->create([
            'application_id' => $application->id,
            'slug' => 'artist',
            'name' => 'Artista',
            'permissions' => ['artist.profile.manage', 'artist.events.view'],
            'is_system' => true,
        ]);

        $user = $this->createUser();

        ApplicationProfileAssignment::query()->create([
            'application_id' => $application->id,
            'user_id' => $user->id,
            'profile_id' => $producer->id,
            'scope_type' => 'application',
            'scope_id' => 0,
            'status' => 'active',
            'source' => 'test',
        ]);

        ApplicationProfileAssignment::query()->create([
            'application_id' => $application->id,
            'user_id' => $user->id,
            'profile_id' => $artist->id,
            'scope_type' => 'artist',
            'scope_id' => 77,
            'status' => 'active',
            'source' => 'test',
        ]);

        $access = app(ApplicationProfileAccessService::class);

        $this->assertEqualsCanonicalizing(
            ['production.create', 'event.create'],
            $access->permissions($user, $application->id),
        );

        $this->assertEqualsCanonicalizing(
            ['production.create', 'event.create', 'artist.profile.manage', 'artist.events.view'],
            $access->permissions($user, $application->id, 'artist', 77),
        );

        $this->assertTrue($user->hasPermission('production.create', $application->id, 'artist', 77));
        $this->assertTrue($user->hasPermission('artist.profile.manage', $application->id, 'artist', 77));
        $this->assertFalse($user->hasPermission('artist.profile.manage', $application->id, 'artist', 78));
    }

    public function test_revoked_and_other_application_profiles_do_not_leak_permissions(): void
    {
        $cutinapp = Application::query()->create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'is_active' => true,
        ]);

        $nexus = Application::query()->create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'is_active' => true,
        ]);

        $cutinappProfile = ApplicationProfile::query()->create([
            'application_id' => $cutinapp->id,
            'slug' => 'producer',
            'name' => 'Produtor',
            'permissions' => ['event.create'],
            'is_system' => true,
        ]);

        $nexusProfile = ApplicationProfile::query()->create([
            'application_id' => $nexus->id,
            'slug' => 'owner',
            'name' => 'Proprietário',
            'permissions' => ['catalog.manage'],
            'is_system' => true,
        ]);

        $user = $this->createUser();

        ApplicationProfileAssignment::query()->create([
            'application_id' => $cutinapp->id,
            'user_id' => $user->id,
            'profile_id' => $cutinappProfile->id,
            'scope_type' => 'application',
            'scope_id' => 0,
            'status' => 'revoked',
            'source' => 'test',
            'revoked_at' => now(),
        ]);

        ApplicationProfileAssignment::query()->create([
            'application_id' => $nexus->id,
            'user_id' => $user->id,
            'profile_id' => $nexusProfile->id,
            'scope_type' => 'application',
            'scope_id' => 0,
            'status' => 'active',
            'source' => 'test',
        ]);

        $access = app(ApplicationProfileAccessService::class);

        $this->assertFalse($access->hasPermission($user, 'event.create', $cutinapp->id));
        $this->assertFalse($access->hasPermission($user, 'catalog.manage', $cutinapp->id));
        $this->assertTrue($access->hasPermission($user, 'catalog.manage', $nexus->id));
    }

    private function createUser(): User
    {
        return User::query()->create([
            'first_name' => 'Profile',
            'last_name' => 'Test',
            'email' => uniqid('profile-', true).'@example.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }
}
