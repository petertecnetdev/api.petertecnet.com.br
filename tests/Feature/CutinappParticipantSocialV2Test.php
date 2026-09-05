<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappParticipantSocialV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_directory_explains_affinity_without_exposing_private_contact_data(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $viewer = $this->user('Ana', 'Viewer', 'ana-viewer@cutinapp.test', 'Recife', 'PE');
        $candidate = $this->user('Bia', 'Candidate', 'bia-candidate@cutinapp.test', 'Recife', 'PE');
        $this->attach($app, $viewer);
        $this->attach($app, $candidate);
        $this->preferences($app, $viewer, ['samba', 'festivais']);
        $this->preferences($app, $candidate, ['samba', 'teatro']);

        DB::table('follows')->insert([
            'app_id' => $app->id,
            'user_id' => $candidate->id,
            'target_type' => 'user',
            'target_id' => $viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants')
            ->assertOk()
            ->assertJsonPath('participants.data.0.id', $candidate->id)
            ->assertJsonPath('participants.data.0.city', 'Recife')
            ->assertJsonPath('participants.data.0.is_following_viewer', true)
            ->assertJsonPath('participants.data.0.is_connection', false)
            ->assertJsonMissingPath('participants.data.0.email')
            ->assertJsonMissingPath('participants.data.0.phone')
            ->assertJsonMissingPath('participants.data.0.address');

        $reasons = $response->json('participants.data.0.affinity_reasons');
        $this->assertContains('Também segue você', $reasons);
        $this->assertContains('1 interesse em comum', $reasons);
        $this->assertContains('Vocês estão na mesma cidade', $reasons);
        $this->assertGreaterThan(0, $response->json('participants.data.0.affinity_score'));
    }

    public function test_user_can_leave_discovery_and_hide_social_signals_without_losing_existing_followers(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $viewer = $this->user('Carlos', 'Viewer', 'carlos-viewer@cutinapp.test', 'Goiânia', 'GO');
        $private = $this->user('Dani', 'Private', 'dani-private@cutinapp.test', 'Goiânia', 'GO');
        $this->attach($app, $viewer);
        $this->attach($app, $private);
        $this->preferences($app, $private, ['rock']);

        $this->withHeaders($this->headersFor($private))
            ->putJson('/api/v1/apps/cutinapp/participants/me/social-settings', [
                'discoverable' => false,
                'show_city' => false,
                'show_interests' => false,
                'show_event_interests' => false,
                'allow_follows' => true,
            ])
            ->assertOk()
            ->assertJsonPath('settings.discoverable', false)
            ->assertJsonPath('settings.show_city', false);

        $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants')
            ->assertOk()
            ->assertJsonMissing(['id' => $private->id]);

        DB::table('follows')->insert([
            'app_id' => $app->id,
            'user_id' => $viewer->id,
            'target_type' => 'user',
            'target_id' => $private->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants')
            ->assertOk()
            ->assertJsonFragment(['id' => $private->id, 'city' => null, 'uf' => null, 'interests' => []]);

        $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants/'.$private->id)
            ->assertOk()
            ->assertJsonPath('participant.city', null)
            ->assertJsonPath('interests', [])
            ->assertJsonPath('visibility.show_interests', false)
            ->assertJsonPath('visibility.show_event_interests', false);
    }

    public function test_reciprocal_follow_becomes_connection_and_follow_opt_out_is_enforced(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $viewer = $this->user('Eva', 'Viewer', 'eva-viewer@cutinapp.test', 'São Paulo', 'SP');
        $candidate = $this->user('Fê', 'Candidate', 'fe-candidate@cutinapp.test', 'São Paulo', 'SP');
        $locked = $this->user('Gui', 'Locked', 'gui-locked@cutinapp.test', 'São Paulo', 'SP');
        foreach ([$viewer, $candidate, $locked] as $user) $this->attach($app, $user);

        DB::table('follows')->insert([
            'app_id' => $app->id,
            'user_id' => $candidate->id,
            'target_type' => 'user',
            'target_id' => $viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($viewer))
            ->postJson('/api/v1/apps/cutinapp/participants/'.$candidate->id.'/follow')
            ->assertOk()
            ->assertJsonPath('following', true)
            ->assertJsonPath('is_connection', true);

        $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('is_connection', true);

        $this->withHeaders($this->headersFor($locked))
            ->putJson('/api/v1/apps/cutinapp/participants/me/social-settings', [
                'discoverable' => true,
                'show_city' => true,
                'show_interests' => true,
                'show_event_interests' => true,
                'allow_follows' => false,
            ])->assertOk();

        $this->withHeaders($this->headersFor($viewer))
            ->postJson('/api/v1/apps/cutinapp/participants/'.$locked->id.'/follow')
            ->assertForbidden();
    }

    private function user(string $firstName, string $lastName, string $email, string $city, string $uf): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'user_name' => strtolower($firstName).'-'.substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
            'phone' => '11999999999',
            'address' => 'Rua privada, 123',
            'city' => $city,
            'uf' => $uf,
        ]);
    }

    private function attach(Application $app, User $user): void
    {
        DB::table('application_user')->insert([
            'application_id' => $app->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function preferences(Application $app, User $user, array $interests): void
    {
        DB::table('application_user_preferences')->insert([
            'app_id' => $app->id,
            'user_id' => $user->id,
            'radius_km' => 50,
            'interests' => json_encode($interests),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }
}
