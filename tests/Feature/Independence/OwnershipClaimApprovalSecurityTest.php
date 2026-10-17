<?php

declare(strict_types=1);

namespace Tests\Feature\Independence;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Services\Independence\ListingCommercePolicy;
use App\Services\Independence\ScopedEntityGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OwnershipClaimApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $email): User
    {
        return User::query()->create([
            'first_name' => 'Teste',
            'last_name' => 'Independence',
            'email' => $email,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }

    private function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function requestClaim(string $type, int $entityId, User $user): int
    {
        return (int) $this->withHeaders($this->headers($user))
            ->postJson('/api/v1/apps/cutinapp/independence/claims', [
                'entity_type' => $type,
                'entity_id' => $entityId,
                'name' => 'Reivindicante Teste',
                'email' => $user->email,
                'consent' => true,
            ])->assertCreated()->json('data.id');
    }

    private function approve(int $claimId, User $admin, array $overrides = [])
    {
        return $this->withHeaders($this->headers($admin))
            ->patchJson('/api/v1/apps/cutinapp/admin/independence/claims/'.$claimId, array_merge([
                'decision' => 'approved',
                'review_note' => 'Confirmada identidade pelo canal institucional e origem da página.',
                'verification_method' => 'official_channel',
                'evidence_reference' => 'protocolo-curadoria-2026-1017',
                'verification_attested' => true,
            ], $overrides));
    }

    private function production(int $appId, User $curator): Production
    {
        static $number = 0;
        $number++;
        $production = Production::query()->create([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'user_id' => $curator->id,
            'name' => 'Produção Curada '.$number,
            'slug' => 'producao-curada-'.$number,
            'is_published' => true,
        ]);
        $production->forceFill([
            'ownership_status' => 'unclaimed',
            'listing_source' => 'public_curated',
            'source_url' => 'https://example.test/curadoria/'.$number,
        ])->saveOrFail();

        return $production->fresh();
    }

    public function test_approved_production_is_scoped_without_event_or_commerce_permissions(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $admin = $this->person(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $curator = $this->person('curador-producao@example.test');
        $claimant = $this->person('titular-producao@example.test');
        $production = $this->production($app->id, $curator);

        $claimId = $this->requestClaim('production', $production->id, $claimant);
        $this->approve($claimId, $admin)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('independence_claim_audits', [
            'app_id' => $app->id, 'claim_id' => $claimId,
            'actor_user_id' => $admin->id, 'decision' => 'approved',
        ]);
        $this->assertDatabaseHas('independence_entity_grants', [
            'app_id' => $app->id, 'entity_type' => 'production',
            'entity_id' => $production->id, 'user_id' => $claimant->id,
        ]);
        $this->assertSame($curator->id, (int) $production->fresh()->user_id);
        $this->assertNull($production->fresh()->commerce_authorized_at);
        $this->assertSame('manual_approved', $production->fresh()->verification_status);

        $grants = app(ScopedEntityGrant::class);
        $this->assertTrue($grants->canManage($app->id, 'production', $production->id, $claimant->id));
        $this->assertFalse($grants->canManage($app->id, 'event', $production->id, $claimant->id));
        $otherApp = $this->applicationFixture('inkap');
        $this->assertFalse($grants->canManage($otherApp->id, 'production', $production->id, $claimant->id));

        $this->approve($claimId, $admin)->assertStatus(409);
        $this->assertDatabaseCount('independence_claim_audits', 1);
    }

    public function test_event_approval_does_not_assign_production_or_enable_checkout(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $admin = $this->person(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $curator = $this->person('curador-evento@example.test');
        $claimant = $this->person('titular-evento@example.test');
        $production = $this->production($app->id, $curator);
        $event = Event::query()->create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'created_by_user_id' => $curator->id,
            'title' => 'Evento de Curadoria',
            'slug' => 'evento-de-curadoria',
            'kind' => 'commercial',
            'event_format' => 'online',
            'online_url' => 'https://example.org/transmissao',
            'start_date' => now()->addDays(10),
            'end_date' => now()->addDays(10)->addHours(3),
            'is_published' => true,
        ]);
        $event->forceFill([
            'ownership_status' => 'unclaimed',
            'listing_source' => 'public_curated',
            'source_url' => 'https://example.test/evento',
        ])->saveOrFail();

        $claimId = $this->requestClaim('event', $event->id, $claimant);
        $this->approve($claimId, $admin)->assertOk();

        $grants = app(ScopedEntityGrant::class);
        $this->assertTrue($grants->canManage($app->id, 'event', $event->id, $claimant->id));
        $this->assertFalse($grants->canManage($app->id, 'production', $production->id, $claimant->id));
        $this->assertSame($curator->id, (int) $event->fresh()->created_by_user_id);
        $this->assertFalse(app(ListingCommercePolicy::class)->allowsLegacyOrVerified($event->fresh()));
    }

    public function test_unverified_provenance_and_missing_human_proof_block_approval(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $admin = $this->person(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $curator = $this->person('curador-incompleto@example.test');
        $claimant = $this->person('titular-incompleto@example.test');
        $production = $this->production($app->id, $curator);
        $claimId = $this->requestClaim('production', $production->id, $claimant);

        $this->approve($claimId, $admin, [
            'verification_attested' => false,
        ])->assertUnprocessable();

        $production->forceFill(['source_url' => null])->saveOrFail();
        $this->approve($claimId, $admin)->assertStatus(409);
        $this->assertDatabaseCount('independence_entity_grants', 0);
        $this->assertDatabaseCount('independence_claim_audits', 0);
        $this->assertDatabaseHas('independence_ownership_claims', [
            'id' => $claimId, 'status' => 'pending',
        ]);
    }

    public function test_claimant_and_unprivileged_user_cannot_approve(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $curator = $this->person('curador-bloqueio@example.test');
        $claimant = $this->person('titular-bloqueio@example.test');
        $stranger = $this->person('terceiro-bloqueio@example.test');
        $production = $this->production($app->id, $curator);
        $claimId = $this->requestClaim('production', $production->id, $claimant);

        $this->approve($claimId, $claimant)->assertForbidden();
        $this->approve($claimId, $stranger)->assertForbidden();
        $this->assertDatabaseCount('independence_entity_grants', 0);
    }

    public function test_artist_approval_links_only_a_verified_account(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $admin = $this->person(ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $claimant = $this->person('titular-artista@example.test');
        $artist = Artist::query()->create([
            'app_id' => $app->id,
            'stage_name' => 'Artista de Curadoria',
            'slug' => 'artista-de-curadoria',
            'is_active' => true,
            'is_published' => true,
        ]);
        $claimId = $this->requestClaim('artist', $artist->id, $claimant);
        $this->approve($claimId, $admin)->assertOk();
        $this->assertSame($claimant->id, (int) $artist->fresh()->user_id);
        $this->assertNotNull($artist->fresh()->claimed_at);
        $this->assertDatabaseCount('independence_entity_grants', 0);
    }
}
