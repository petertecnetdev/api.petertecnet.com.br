<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDirectoryRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_directory_and_catalog_are_scoped_by_application_context(): void
    {
        $target = Application::query()->create([
            'name' => 'Catalog Directory Test',
            'slug' => 'catalog-directory-test',
            'is_active' => true,
        ]);

        $other = Application::query()->create([
            'name' => 'Other Application',
            'slug' => 'other-application-test',
            'is_active' => true,
        ]);

        $owner = User::factory()->create();

        $visible = Establishment::query()->create([
            'user_id' => $owner->id,
            'app_id' => $target->id,
            'name' => 'Visible Company',
            'fantasy' => 'Visible Company',
            'slug' => 'visible-company',
            'is_cancelled' => false,
        ]);

        Establishment::query()->create([
            'user_id' => $owner->id,
            'app_id' => $other->id,
            'name' => 'Unlinked Company',
            'fantasy' => 'Unlinked Company',
            'slug' => 'unlinked-company',
            'is_cancelled' => false,
        ]);

        $directory = $this->getJson('/api/v1/apps/catalog-directory-test/directory');

        $directory->assertOk()
            ->assertJsonPath('scope.target_application_id', $target->id)
            ->assertJsonCount(1, 'establishments')
            ->assertJsonPath('establishments.0.id', $visible->id)
            ->assertJsonMissing(['slug' => 'unlinked-company']);

        $this->getJson('/api/v1/apps/catalog-directory-test/directory/catalog/visible-company')
            ->assertOk()
            ->assertJsonPath('target_application_id', $target->id)
            ->assertJsonPath('establishment.id', $visible->id)
            ->assertJsonMissingPath('establishment.is_nexus_native');

        $this->getJson('/api/v1/apps/catalog-directory-test/directory/catalog/unlinked-company')
            ->assertNotFound();
    }
}
