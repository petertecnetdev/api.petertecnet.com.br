<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkforceTeamManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_search_add_list_and_remove_team_member(): void
    {
        Mail::fake();

        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => 'Workforce',
            'email' => 'workforce-owner@example.test',
            'user_name' => 'workforce-owner',
            'password' => Hash::make('Test1234!'),
        ]);

        $candidate = User::create([
            'first_name' => 'Candidate',
            'last_name' => 'Professional',
            'email' => 'candidate-professional@example.test',
            'user_name' => 'candidate-professional',
            'phone' => '11999998888',
            'password' => Hash::make('Test1234!'),
        ]);

        $application = $this->applicationFixture('workforce-test', [
            'name' => 'Workforce Test',
            'is_active' => true,
            'capabilities' => ['workforce'],
        ]);

        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => 'Workforce Establishment',
            'slug' => 'workforce-establishment',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.auth('api')->login($owner),
        ];

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/workforce-test/team-members?establishment_id='.$establishmentId)
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/workforce-test/team-members/candidates?establishment_id='.$establishmentId.'&q=candidate-professional')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $candidate->id)
            ->assertJsonPath('data.0.is_team_member', false)
            ->assertJsonPath('data.0.team_member', null);

        $create = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/workforce-test/team-members', [
                'user_id' => $candidate->id,
                'establishment_id' => $establishmentId,
                'role' => 'profissional',
                'permissions' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('employer.user_id', $candidate->id)
            ->assertJsonPath('employer.establishment_id', $establishmentId);

        $teamMemberId = (int) $create->json('employer.id');
        $this->assertGreaterThan(0, $teamMemberId);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/workforce-test/team-members?establishment_id='.$establishmentId)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $teamMemberId)
            ->assertJsonPath('data.0.user.email', $candidate->email);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/workforce-test/team-members/candidates?establishment_id='.$establishmentId.'&q='.$candidate->email)
            ->assertOk()
            ->assertJsonPath('data.0.is_team_member', true)
            ->assertJsonPath('data.0.team_member.id', $teamMemberId)
            ->assertJsonPath('data.0.team_member.role', 'profissional');

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/apps/workforce-test/team-members/'.$teamMemberId)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/workforce-test/team-members?establishment_id='.$establishmentId)
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);
    }
}
