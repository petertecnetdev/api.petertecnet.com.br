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

        $establishmentId = $this->createEstablishment($application->id, $owner->id, 'Workforce Establishment', 'workforce-establishment');

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

    public function test_same_workforce_domain_is_reused_with_strict_application_isolation(): void
    {
        Mail::fake();

        $ownerA = $this->user('owner-a@example.test', 'owner-a');
        $ownerB = $this->user('owner-b@example.test', 'owner-b');
        $candidate = $this->user('shared-candidate@example.test', 'shared-candidate');

        $appA = $this->applicationFixture('workforce-a', [
            'name' => 'Workforce A',
            'is_active' => true,
            'capabilities' => ['workforce'],
        ]);
        $appB = $this->applicationFixture('workforce-b', [
            'name' => 'Workforce B',
            'is_active' => true,
            'capabilities' => ['workforce'],
        ]);

        $establishmentA = $this->createEstablishment($appA->id, $ownerA->id, 'Equipe A', 'equipe-a');
        $establishmentB = $this->createEstablishment($appB->id, $ownerB->id, 'Equipe B', 'equipe-b');

        $headersA = ['Authorization' => 'Bearer '.auth('api')->login($ownerA)];
        $headersB = ['Authorization' => 'Bearer '.auth('api')->login($ownerB)];

        $this->withHeaders($headersA)
            ->getJson('/api/v1/apps/workforce-a/team-members?establishment_id='.$establishmentB)
            ->assertNotFound();

        $this->withHeaders($headersA)
            ->getJson('/api/v1/apps/workforce-a/team-members/candidates?establishment_id='.$establishmentB.'&q=shared-candidate')
            ->assertNotFound();

        $this->withHeaders($headersA)
            ->postJson('/api/v1/apps/workforce-a/team-members', [
                'user_id' => $candidate->id,
                'establishment_id' => $establishmentB,
                'role' => 'profissional',
            ])
            ->assertNotFound();

        $created = $this->withHeaders($headersB)
            ->postJson('/api/v1/apps/workforce-b/team-members', [
                'user_id' => $candidate->id,
                'establishment_id' => $establishmentB,
                'role' => 'profissional',
                'permissions' => ['appointments.manage'],
            ])
            ->assertCreated()
            ->assertJsonPath('employer.establishment_id', $establishmentB)
            ->assertJsonPath('employer.user_id', $candidate->id);

        $teamMemberId = (int) $created->json('employer.id');

        $this->withHeaders($headersB)
            ->getJson('/api/v1/apps/workforce-b/team-members?establishment_id='.$establishmentB)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $teamMemberId);

        $this->withHeaders($headersA)
            ->getJson('/api/v1/apps/workforce-a/team-members?establishment_id='.$establishmentA)
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->withHeaders($headersA)
            ->deleteJson('/api/v1/apps/workforce-a/team-members/'.$teamMemberId)
            ->assertNotFound();

        $this->withHeaders($headersB)
            ->getJson('/api/v1/apps/workforce-b/team-members/candidates?establishment_id='.$establishmentB.'&q=shared-candidate')
            ->assertOk()
            ->assertJsonPath('data.0.is_team_member', true)
            ->assertJsonPath('data.0.team_member.id', $teamMemberId);
    }

    private function user(string $email, string $username): User
    {
        return User::create([
            'first_name' => ucfirst(str_replace('-', ' ', $username)),
            'last_name' => 'Workforce',
            'email' => $email,
            'user_name' => $username,
            'password' => Hash::make('Test1234!'),
        ]);
    }

    private function createEstablishment(int $applicationId, int $ownerId, string $name, string $slug): int
    {
        return DB::table('establishments')->insertGetId([
            'app_id' => $applicationId,
            'name' => $name,
            'slug' => $slug,
            'user_id' => $ownerId,
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
