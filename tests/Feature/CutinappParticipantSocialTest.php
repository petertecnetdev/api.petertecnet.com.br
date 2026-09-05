<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappParticipantSocialTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_directory_is_private_app_scoped_and_excludes_inactive_members(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $viewer = $this->user('Viewer', 'viewer-participant@cutinapp.test', 'Belo Horizonte', 'MG');
        $compatible = $this->user('Compatible', 'compatible-participant@cutinapp.test', 'Belo Horizonte', 'MG');
        $inactive = $this->user('Inactive', 'inactive-participant@cutinapp.test', 'Belo Horizonte', 'MG');

        $this->membership($app, $viewer, 'active');
        $this->membership($app, $compatible, 'active');
        $this->membership($app, $inactive, 'inactive');
        $this->preferences($app, $viewer, ['House', 'Festivais']);
        $this->preferences($app, $compatible, ['House', 'Techno']);

        $response = $this->withHeaders($this->headersFor($viewer))
            ->getJson('/api/v1/apps/cutinapp/participants')
            ->assertOk()
            ->assertJsonPath('participants.total', 1)
            ->assertJsonPath('participants.data.0.id', $compatible->id)
            ->assertJsonPath('participants.data.0.shared_interests.0', 'House')
            ->assertJsonPath('participants.data.0.affinity_score', 28)
            ->assertJsonMissing(['first_name' => 'Inactive']);

        $payload = $response->json('participants.data.0');
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('address', $payload);
    }

    public function test_participants_can_follow_each_other_and_open_social_profiles(): void
    {
        $app = Application::where('slug', 'cutinapp')->firstOrFail();
        $viewer = $this->user('Ana Viewer', 'ana-viewer@cutinapp.test', 'Recife', 'PE');
        $participant = $this->user('Bia Participant', 'bia-participant@cutinapp.test', 'Recife', 'PE');

        $this->membership($app, $viewer, 'active');
        $this->membership($app, $participant, 'active');
        $this->preferences($app, $viewer, ['Samba', 'Pagode']);
        $this->preferences($app, $participant, ['Samba', 'MPB']);
        $headers = $this->headersFor($viewer);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/participants/'.$participant->id.'/follow')
            ->assertOk()
            ->assertJsonPath('following', true);

        $this->assertDatabaseHas('follows', [
            'app_id' => $app->id,
            'user_id' => $viewer->id,
            'target_type' => 'user',
            'target_id' => $participant->id,
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/participants/'.$participant->id)
            ->assertOk()
            ->assertJsonPath('participant.id', $participant->id)
            ->assertJsonPath('shared_interests.0', 'Samba')
            ->assertJsonPath('is_following', true)
            ->assertJsonPath('stats.followers', 1)
            ->assertJsonPath('affinity_score', 28)
            ->assertJsonMissingPath('participant.email')
            ->assertJsonMissingPath('participant.phone');

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/participants/'.$viewer->id.'/follow')
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/apps/cutinapp/participants/'.$participant->id.'/follow')
            ->assertOk()
            ->assertJsonPath('following', false);

        $this->assertDatabaseMissing('follows', [
            'app_id' => $app->id,
            'user_id' => $viewer->id,
            'target_type' => 'user',
            'target_id' => $participant->id,
        ]);
    }

    private function user(string $name, string $email, string $city, string $uf): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)).'-'.substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
            'city' => $city,
            'uf' => $uf,
            'phone' => '+55 31 99999-0000',
            'address' => 'Rua privada, 10',
            'about' => 'Perfil participante para testes sociais.',
        ]);
    }

    private function membership(Application $app, User $user, string $status): void
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

    private function preferences(Application $app, User $user, array $interests): void
    {
        DB::table('application_user_preferences')->insert([
            'app_id' => $app->id,
            'user_id' => $user->id,
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
