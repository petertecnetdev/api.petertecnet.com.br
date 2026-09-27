<?php

namespace Tests\Feature;

use App\Domain\Media\Library\Http\Controllers\AdminMediaLibraryController;
use App\Domain\Media\Library\Models\MediaAsset;
use App\Http\Middleware\PeterTecnetAdminApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaLibraryPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_require_authentication_and_owner_admin_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/admin/media-library/assets'
                && in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertSame(AdminMediaLibraryController::class.'@index', $route->getActionName());
        $this->assertContains('auth:api', $route->gatherMiddleware());
        $this->assertContains(PeterTecnetAdminApi::class, $route->gatherMiddleware());
    }

    public function test_public_marketing_endpoint_is_application_scoped(): void
    {
        $firstApp = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp']);
        $secondApp = $this->applicationFixture('media-other-app', ['name' => 'Other App']);

        $first = $this->asset($firstApp->id, 'First logo', true, 'public');
        $this->asset($secondApp->id, 'Other logo', true, 'public');

        $this->getJson('/api/v1/apps/cutinapp/media-library/marketing?category=brand')
            ->assertOk()
            ->assertJsonCount(1, 'assets')
            ->assertJsonPath('assets.0.id', $first->id)
            ->assertJsonPath('assets.0.application.slug', 'cutinapp')
            ->assertJsonMissingPath('assets.0.storage');

        $this->getJson('/api/v1/apps/media-other-app/media-library/marketing?category=brand')
            ->assertOk()
            ->assertJsonCount(1, 'assets')
            ->assertJsonPath('assets.0.application.slug', 'media-other-app');
    }

    public function test_public_endpoint_hides_private_unapproved_and_archived_assets(): void
    {
        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp']);

        $this->asset($app->id, 'Private', true, 'private');
        $this->asset($app->id, 'Unapproved', false, 'public');
        $archived = $this->asset($app->id, 'Archived', true, 'public');
        $archived->update(['status' => 'archived']);

        $this->getJson('/api/v1/apps/cutinapp/media-library/marketing')
            ->assertOk()
            ->assertJsonCount(0, 'assets');
    }

    private function asset(int $applicationId, string $name, bool $approved, string $visibility): MediaAsset
    {
        return MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_id' => $applicationId,
            'name' => $name,
            'kind' => 'image',
            'category' => 'brand',
            'purpose' => 'feed',
            'public_url' => 'https://example.test/'.Str::slug($name).'.png',
            'visibility' => $visibility,
            'status' => 'ready',
            'is_official' => true,
            'is_marketing_approved' => $approved,
            'is_ai_generated' => false,
        ]);
    }
}
