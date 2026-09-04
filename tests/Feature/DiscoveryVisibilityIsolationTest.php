<?php

namespace Tests\Feature;

use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscoveryVisibilityIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unapproved_resource_never_enters_discovery_for_application_that_requires_approval(): void
    {
        $application = $this->applicationFixture('moderated-catalog', [
            'name' => 'Moderated Catalog',
            'is_active' => true,
        ]);
        config()->set('platform.approval_required_apps', ['moderated-catalog']);

        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => 'Empresa Pendente',
            'fantasy' => 'Empresa Pendente',
            'slug' => 'empresa-pendente',
            'city' => 'Governador Valadares',
            'uf' => 'MG',
            'is_published' => true,
            'is_approved' => false,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('items')->insertGetId([
            'app_id' => $application->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishmentId,
            'name' => 'Produto Pendente',
            'slug' => 'produto-pendente',
            'type' => 'product',
            'price' => 10,
            'status' => true,
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(DiscoverySearchIndexService::class)->rebuild();

        $this->assertDatabaseMissing('discovery_search_documents', [
            'application_id' => $application->id,
            'document_type' => 'establishment',
            'document_id' => $establishmentId,
        ]);
        $this->assertDatabaseMissing('discovery_search_documents', [
            'application_id' => $application->id,
            'document_type' => 'item',
            'document_id' => $itemId,
        ]);

        $this->getJson('/api/v1/discovery/establishments/empresa-pendente?application=moderated-catalog')
            ->assertNotFound();
        $this->getJson('/api/v1/discovery/ranked-search?q=produto+pendente&application=moderated-catalog')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Produto Pendente']);

        DB::table('establishments')->where('id', $establishmentId)->update([
            'is_approved' => true,
            'updated_at' => now(),
        ]);
        app(DiscoverySearchIndexService::class)->rebuild();

        $this->assertDatabaseHas('discovery_search_documents', [
            'application_id' => $application->id,
            'document_type' => 'establishment',
            'document_id' => $establishmentId,
        ]);
        $this->assertDatabaseHas('discovery_search_documents', [
            'application_id' => $application->id,
            'document_type' => 'item',
            'document_id' => $itemId,
        ]);

        $this->getJson('/api/v1/discovery/establishments/empresa-pendente?application=moderated-catalog')
            ->assertOk();
        $this->getJson('/api/v1/discovery/ranked-search?q=produto+pendente&application=moderated-catalog')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Produto Pendente']);
    }

    public function test_same_resource_identity_is_isolated_by_application_in_search_index(): void
    {
        $first = $this->applicationFixture('catalog-alpha', ['is_active' => true]);
        $second = $this->applicationFixture('catalog-beta', ['is_active' => true]);

        $firstEstablishment = DB::table('establishments')->insertGetId([
            'app_id' => $first->id,
            'name' => 'Catálogo Alpha',
            'fantasy' => 'Catálogo Alpha',
            'slug' => 'catalogo-alpha',
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondEstablishment = DB::table('establishments')->insertGetId([
            'app_id' => $second->id,
            'name' => 'Catálogo Beta',
            'fantasy' => 'Catálogo Beta',
            'slug' => 'catalogo-beta',
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('items')->insert([
            [
                'app_id' => $first->id,
                'entity_name' => 'establishment',
                'entity_id' => $firstEstablishment,
                'name' => 'Oferta Alpha',
                'slug' => 'oferta-alpha',
                'type' => 'product',
                'price' => 20,
                'status' => true,
                'is_featured' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'app_id' => $second->id,
                'entity_name' => 'establishment',
                'entity_id' => $secondEstablishment,
                'name' => 'Oferta Beta',
                'slug' => 'oferta-beta',
                'type' => 'product',
                'price' => 30,
                'status' => true,
                'is_featured' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app(DiscoverySearchIndexService::class)->rebuild();

        $this->getJson('/api/v1/discovery/ranked-search?q=oferta&application=catalog-alpha')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Oferta Alpha'])
            ->assertJsonMissing(['title' => 'Oferta Beta']);

        $this->getJson('/api/v1/discovery/ranked-search?q=oferta&application=catalog-beta')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Oferta Beta'])
            ->assertJsonMissing(['title' => 'Oferta Alpha']);
    }
}
