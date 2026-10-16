<?php

declare(strict_types=1);

namespace Tests\Feature\Independence;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OwnershipClaimIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function claimant(bool $verified = true): User
    {
        return User::query()->create([
            'first_name' => 'Pessoa',
            'last_name' => 'Artista',
            'email' => 'solicitante@example.test',
            'email_verified_at' => $verified ? now() : null,
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }

    private function artist(int $appId, ?int $userId = null): Artist
    {
        static $sequence = 0;
        $sequence++;
        return Artist::query()->create([
            'app_id' => $appId,
            'user_id' => $userId,
            'stage_name' => 'Artista Independente '.$sequence,
            'slug' => 'artista-independente-'.$sequence,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    private function payload(int $artistId): array
    {
        return [
            'entity_type' => 'artist',
            'entity_id' => $artistId,
            'name' => 'Pessoa Artista',
            'email' => 'solicitante@example.test',
            'consent' => true,
        ];
    }

    private function token(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_public_artist_exposes_claimability_without_granting_management(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $claimant = $this->claimant();
        $unclaimed = $this->artist($app->id);
        $claimed = $this->artist($app->id, $claimant->id);
        $claimed->forceFill(['claimed_at' => now()])->save();

        $this->getJson('/api/v1/apps/cutinapp/artists/'.$unclaimed->slug)
            ->assertOk()->assertJsonPath('artist.ownership_status', 'unclaimed');
        $this->getJson('/api/v1/apps/cutinapp/artists/'.$claimed->slug)
            ->assertOk()->assertJsonPath('artist.ownership_status', 'verified');
        $this->assertNull($unclaimed->fresh()->user_id);
    }

    public function test_authenticated_verified_user_can_submit_artist_claim_without_ownership_transfer(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $user = $this->claimant();
        $artist = $this->artist($app->id);

        $response = $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id));

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('independence_ownership_claims', [
            'app_id' => $app->id,
            'entity_type' => 'artist',
            'entity_id' => $artist->id,
            'claimant_user_id' => $user->id,
            'status' => 'pending',
        ]);
        $this->assertNull($artist->fresh()->user_id);

        $this->withHeaders($this->token($user))
            ->getJson('/api/v1/apps/cutinapp/independence/claims/mine')
            ->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $user = $this->claimant();
        $artist = $this->artist($app->id);

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id))
            ->assertCreated();
        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id))
            ->assertOk();

        $this->assertDatabaseCount('independence_ownership_claims', 1);
    }

    public function test_other_app_claim_is_not_visible_and_cannot_be_submitted(): void
    {
        $this->applicationFixture('cutinapp');
        $foreignApp = $this->applicationFixture('inkap');
        $user = $this->claimant();
        $artist = $this->artist($foreignApp->id);

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id))
            ->assertNotFound();

        $this->assertDatabaseCount('independence_ownership_claims', 0);
    }

    public function test_unverified_email_and_email_mismatch_cannot_claim(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $user = $this->claimant(false);
        $artist = $this->artist($app->id);

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id))
            ->assertForbidden();

        $user->forceFill(['email_verified_at' => now()])->save();
        $payload = $this->payload($artist->id);
        $payload['email'] = 'terceiro@example.test';

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('independence_ownership_claims', 0);
    }

    public function test_request_does_not_allow_privilege_injection_or_claim_existing_artist(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $user = $this->claimant();
        $artist = $this->artist($app->id);
        $payload = $this->payload($artist->id);
        $payload['owner_user_id'] = $user->id;

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $payload)
            ->assertUnprocessable();

        $artist->forceFill(['user_id' => $user->id, 'claimed_at' => now()])->save();

        $this->withHeaders($this->token($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', $this->payload($artist->id))
            ->assertStatus(409);
        $this->assertDatabaseCount('independence_ownership_claims', 0);
    }

    public function test_non_admin_cannot_read_review_queue(): void
    {
        $this->applicationFixture('cutinapp');
        $user = $this->claimant();

        $this->withHeaders($this->token($user))
            ->getJson('/api/v1/apps/cutinapp/admin/independence/claims')
            ->assertForbidden();
    }
}
