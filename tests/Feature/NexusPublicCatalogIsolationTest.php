<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NexusPublicCatalogIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlinked_company_cannot_be_opened_through_nexus_public_catalog(): void
    {
        [$nexus, $source, $company] = $this->fixture();

        $this->getJson('/api/nexus/catalog/' . $company->slug . '?app_id=' . $nexus->id)
            ->assertNotFound();

        $this->getJson('/api/nexus/catalog/' . $company->slug . '?app_id=' . $source->id)
            ->assertOk();
    }

    public function test_linked_company_can_be_opened_in_nexus_and_keeps_its_own_active_items(): void
    {
        [$nexus, $source, $company, $user] = $this->fixture();
        $company->applications()->syncWithoutDetaching([
            $nexus->id => ['is_primary' => false],
        ]);

        Item::create([
            'app_id' => $source->id,
            'entity_name' => 'establishment',
            'entity_id' => $company->id,
            'name' => 'Item de origem',
            'slug' => 'item-de-origem',
            'type' => 'product',
            'price' => 25,
            'status' => true,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $response = $this->getJson('/api/nexus/catalog/' . $company->slug . '?app_id=' . $nexus->id);

        $response
            ->assertOk()
            ->assertJsonPath('establishment.catalog_active', true)
            ->assertJsonPath('items.0.name', 'Item de origem');
    }

    public function test_item_from_unlinked_company_cannot_be_opened_through_nexus(): void
    {
        [$nexus, $source, $company, $user] = $this->fixture();

        $item = Item::create([
            'app_id' => $source->id,
            'entity_name' => 'establishment',
            'entity_id' => $company->id,
            'name' => 'Item isolado',
            'slug' => 'item-isolado',
            'type' => 'product',
            'price' => 15,
            'status' => true,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->getJson('/api/nexus/item/' . $item->slug . '?app_id=' . $nexus->id)
            ->assertNotFound();
    }

    public function test_social_preview_is_server_rendered_and_respects_nexus_linkage(): void
    {
        [$nexus, , $company] = $this->fixture();

        $this->get('/api/nexus/share/catalog/' . $company->slug . '?app_id=' . $nexus->id)
            ->assertNotFound();

        $company->applications()->syncWithoutDetaching([
            $nexus->id => ['is_primary' => false],
        ]);

        $response = $this->get('/api/nexus/share/catalog/' . $company->slug . '?app_id=' . $nexus->id);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('og:title', false)
            ->assertSee('og:description', false)
            ->assertSee('https://nexus.petertecnet.com.br/catalog/empresa-isolada-publica', false);
    }

    private function fixture(): array
    {
        $nexus = Application::create([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'is_active' => true,
        ]);
        $source = Application::create([
            'name' => 'Source App',
            'slug' => 'source-app',
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Owner',
            'email' => 'owner-public@nexus.test',
            'user_name' => 'owner-public-nexus',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
        $company = Establishment::create([
            'app_id' => $source->id,
            'name' => 'Empresa isolada pública',
            'slug' => 'empresa-isolada-publica',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_cancelled' => false,
        ]);

        return [$nexus, $source, $company, $user];
    }
}
