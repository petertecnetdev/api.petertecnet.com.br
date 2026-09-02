<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaoraProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_preserves_location_when_coordinates_are_omitted(): void
    {
        $user = User::factory()->create();
        $profileId = $this->profile($user, ['latitude' => -19.9167000, 'longitude' => -43.9345000]);

        $this->actingAs($user, 'api')->putJson('/api/laora/profile', [
            'display_name' => 'Pessoa Teste', 'birthdate' => '1995-05-10', 'gender' => 'woman', 'orientation' => 'bisexual',
            'bio' => 'Perfil de teste', 'interests' => ['música'], 'city' => 'Belo Horizonte', 'uf' => 'MG',
            'age_min' => 18, 'age_max' => 60, 'max_distance_km' => 80, 'preferred_genders' => ['man'], 'discovery_enabled' => true,
        ])->assertOk();

        $profile = DB::table('laora_profiles')->where('id', $profileId)->first();
        $this->assertEqualsWithDelta(-19.9167, (float) $profile->latitude, 0.000001);
        $this->assertEqualsWithDelta(-43.9345, (float) $profile->longitude, 0.000001);
    }

    public function test_discovery_respects_bilateral_gender_preferences(): void
    {
        $me = User::factory()->create();
        $candidate = User::factory()->create();
        $this->profile($me, ['gender' => 'man', 'preferred_genders' => json_encode(['woman'])]);
        $candidateProfile = $this->profile($candidate, ['gender' => 'woman', 'preferred_genders' => json_encode(['woman'])]);
        $this->approvedPhoto($candidateProfile);

        $this->actingAs($me, 'api')->getJson('/api/laora/discover')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        DB::table('laora_profiles')->where('id', $candidateProfile)->update(['preferred_genders' => json_encode(['man'])]);
        $this->actingAs($me, 'api')->getJson('/api/laora/discover')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $candidate->id);
    }

    public function test_reciprocal_like_creates_a_visible_match_for_both_users(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        $p1 = $this->profile($one, ['gender' => 'man', 'preferred_genders' => json_encode(['woman'])]);
        $p2 = $this->profile($two, ['gender' => 'woman', 'preferred_genders' => json_encode(['man'])]);
        $this->approvedPhoto($p1); $this->approvedPhoto($p2);

        $this->actingAs($one, 'api')->postJson('/api/laora/swipes', ['target_user_id' => $two->id, 'action' => 'like'])
            ->assertOk()->assertJsonPath('data.matched', false);
        $this->actingAs($two, 'api')->postJson('/api/laora/swipes', ['target_user_id' => $one->id, 'action' => 'like'])
            ->assertOk()->assertJsonPath('data.matched', true);

        $this->actingAs($one, 'api')->getJson('/api/laora/matches')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($two, 'api')->getJson('/api/laora/matches')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unverified_user_cannot_use_discovery(): void
    {
        $user = User::factory()->unverified()->create();
        $profile = $this->profile($user);
        $this->approvedPhoto($profile);

        $this->actingAs($user, 'api')->getJson('/api/laora/discover')->assertForbidden();
    }

    private function profile(User $user, array $overrides = []): int
    {
        return DB::table('laora_profiles')->insertGetId(array_merge([
            'user_id' => $user->id,
            'display_name' => $user->first_name ?: 'Pessoa',
            'birthdate' => '1995-05-10',
            'gender' => 'woman',
            'orientation' => 'bisexual',
            'bio' => 'Perfil para teste automatizado.',
            'interests' => json_encode(['música']),
            'city' => 'Belo Horizonte', 'uf' => 'MG',
            'latitude' => null, 'longitude' => null,
            'age_min' => 18, 'age_max' => 70, 'max_distance_km' => 100,
            'preferred_genders' => json_encode([]),
            'discovery_enabled' => true, 'is_complete' => true,
            'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function approvedPhoto(int $profileId): void
    {
        DB::table('laora_photos')->insert([
            'profile_id' => $profileId, 'path' => "laora/tests/{$profileId}.jpg", 'position' => 0,
            'is_primary' => true, 'moderation_status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
