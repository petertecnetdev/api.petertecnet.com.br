<?php

namespace Tests\Feature;

use App\Domain\Media\Library\Http\Controllers\AdminMediaLibraryController;
use App\Domain\Media\Library\Models\MediaAsset;
use App\Domain\Media\Library\Services\MediaLibraryService;
use App\Http\Middleware\PeterTecnetAdminApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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

    public function test_upload_service_generates_non_destructive_social_variants(): void
    {
        Storage::fake('public');
        config()->set('media_library.disk', 'public');

        $app = $this->applicationFixture('media-variant-app', ['name' => 'Media Variant App']);
        $service = app(MediaLibraryService::class);

        $asset = $service->createUpload(
            $app->id,
            null,
            UploadedFile::fake()->image('flyer.jpg', 800, 1200),
            [
                'name' => 'Flyer',
                'category' => 'flyer',
                'purpose' => 'feed',
                'visibility' => 'public',
                'is_marketing_approved' => true,
            ]
        );

        $this->assertSame('image', $asset->kind);
        $this->assertSame(800, $asset->width);
        $this->assertSame(1200, $asset->height);
        $this->assertNotEmpty($asset->checksum);
        $this->assertCount(5, $asset->variants);

        $portrait = $asset->variants->firstWhere('name', 'feed_portrait');
        $this->assertNotNull($portrait);
        $this->assertSame(1080, $portrait->width);
        $this->assertSame(1350, $portrait->height);
        $this->assertSame('contain', $portrait->metadata['fit']);
        $this->assertTrue($portrait->metadata['source_preserved']);
        Storage::disk('public')->assertExists($portrait->storage_path);
    }

    public function test_external_registration_refuses_non_https_urls(): void
    {
        $app = $this->applicationFixture('media-secure-url-app', ['name' => 'Secure URL App']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('External media URL must use HTTPS.');

        app(MediaLibraryService::class)->createExternal(
            $app->id,
            null,
            'http://example.test/not-allowed.png',
            ['kind' => 'image']
        );
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
