<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GenericCommerceLinkedEstablishmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_establishment_and_its_source_items_are_available_in_generic_commerce(): void
    {
        config(['services.mercadopago.access_token' => 'test-token']);

        $user = $this->user();
        $source = $this->application('Peter Tecnet', 'peter-tecnet');
        $nexus = $this->application('Nexus', 'nexus');

        $establishment = $this->establishment($user, $source, 'Peter Tecnet', 'peter-tecnet', false);
        $establishment->applications()->attach($nexus->id, ['is_primary' => false]);

        Item::create([
            'app_id' => $source->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $user->id,
            'name' => 'Música Personalizada',
            'price' => 99.90,
            'status' => true,
        ]);

        $this->getJson('/api/v1/apps/nexus/commerce/catalog/peter-tecnet')
            ->assertOk()
            ->assertJsonPath('data.establishment.id', $establishment->id)
            ->assertJsonPath('data.commerce.available', true)
            ->assertJsonFragment(['name' => 'Música Personalizada']);
    }

    public function test_unlinked_establishment_is_not_exposed_to_another_application_commerce_context(): void
    {
        config(['services.mercadopago.access_token' => 'test-token']);

        $user = $this->user();
        $source = $this->application('Peter Tecnet', 'peter-tecnet');
        $this->application('Nexus', 'nexus');

        $this->establishment($user, $source, 'Privado', 'privado', true);

        $this->getJson('/api/v1/apps/nexus/commerce/catalog/privado')
            ->assertNotFound();
    }

    private function user(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        return User::create([
            'first_name' => 'Owner',
            'email' => 'owner-commerce@example.test',
            'user_name' => 'owner-commerce-test',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return Application::create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function establishment(
        User $user,
        Application $app,
        string $name,
        string $slug,
        bool $published
    ): Establishment {
        $id = DB::table('establishments')->insertGetId([
            'app_id' => $app->id,
            'name' => $name,
            'slug' => $slug,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => $published,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Establishment::findOrFail($id);
    }
}
