<?php

declare(strict_types=1);

namespace Tests\Feature\Independence;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CuratedListingClassificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_and_audited_apply_leave_original_author_unchanged(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $admin = User::query()->create([
            'first_name' => 'Revisor',
            'email' => ApplicationAdminService::ROOT_ADMIN_EMAIL,
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
        $listing = Production::query()->create([
            'app_id' => $app->id, 'user_id' => $admin->id, 'name' => 'Produção de curadoria',
            'slug' => 'producao-de-curadoria', 'is_published' => true,
        ]);
        $args = [
            'application' => 'cutinapp', 'type' => 'production', 'id' => $listing->id,
            '--reviewer' => $admin->id, '--source-url' => 'https://example.org/agenda',
            '--reason' => 'Publicação institucional e identidade conferidas pela equipe.',
        ];
        $this->artisan('independence:classify-curated-listing', $args)->assertExitCode(0);
        $this->assertNull($listing->fresh()->ownership_status);

        $this->artisan('independence:classify-curated-listing', [...$args, '--apply' => true])
            ->assertExitCode(0);
        $this->assertSame('unclaimed', $listing->fresh()->ownership_status);
        $this->assertSame($admin->id, (int) $listing->fresh()->user_id);
        $this->assertNull($listing->fresh()->commerce_authorized_at);
        $this->assertDatabaseHas('independence_listing_classification_audits', [
            'app_id' => $app->id, 'entity_type' => 'production',
            'entity_id' => $listing->id, 'actor_user_id' => $admin->id,
        ]);

        $this->artisan('independence:classify-curated-listing', [...$args, '--apply' => true])
            ->assertExitCode(1);
        // A segunda tentativa não pode sobrescrever a origem nem produzir
        // um segundo registro de auditoria para a mesma classificação.
        $this->assertDatabaseCount('independence_listing_classification_audits', 1);
        $this->assertSame('https://example.org/agenda', $listing->fresh()->source_url);
    }
}
