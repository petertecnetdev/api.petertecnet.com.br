<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicUserProfilePreviewIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_active_verified_discoverable_profiles_for_the_application(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $visible = $this->user('Visible', 'visible-preview@cutinapp.test', true);
        $hidden = $this->user('Hidden', 'hidden-preview@cutinapp.test', true);
        $inactive = $this->user('Inactive', 'inactive-preview@cutinapp.test', true);
        $unverified = $this->user('Unverified', 'unverified-preview@cutinapp.test', false);

        $this->attach($app, $visible, 'active');
        $this->attach($app, $hidden, 'active');
        $this->attach($app, $inactive, 'inactive');
        $this->attach($app, $unverified, 'active');

        DB::table('user_social_preferences')->insert([
            'app_id' => $app->id,
            'user_id' => $hidden->id,
            'discoverable' => false,
            'show_city' => true,
            'show_interests' => true,
            'show_event_interests' => true,
            'allow_follows' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/apps/cutinapp/profiles/preview-index?per_page=100')
            ->assertOk()
            ->assertJsonPath('profiles.total', 1)
            ->assertJsonPath('profiles.data.0.id', $visible->id);

        $this->assertSame([['id' => $visible->id]], $response->json('profiles.data'));
    }

    private function user(string $name, string $email, bool $verified): User
    {
        return User::create([
            'first_name' => $name,
            'last_name' => 'Profile',
            'email' => $email,
            'user_name' => strtolower($name).'-preview',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function attach(Application $app, User $user, string $status): void
    {
        DB::table('application_user')->insert([
            'application_id' => $app->id,
            'user_id' => $user->id,
            'status' => $status,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}