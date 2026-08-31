<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappProductionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_producer_can_list_create_reload_show_and_edit_a_cutinapp_production(): void
    {
        $user = $this->user('Produtor Fluxo', 'producer-flow@cutinapp.test');
        $headers = $this->headersFor($user);
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/productions/mine')
            ->assertOk()
            ->assertJsonCount(0, 'productions');

        $create = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => '  Produção Fluxo Completo  ',
                'fantasy' => 'Fluxo Eventos',
                'cnpj' => '12.345.678/0001-95',
                'phone' => '(11) 99999-9999',
                'description' => 'Produção criada pelo teste funcional.',
                'city' => 'São Paulo',
                'uf' => 'sp',
                'address' => 'Rua Teste, 123',
                'website_url' => 'cutinapp.petertecnet.com.br',
                'instagram_url' => '@cutinapp',
            ])
            ->assertCreated()
            ->assertJsonPath('production.name', 'Produção Fluxo Completo')
            ->assertJsonPath('production.cnpj', '12345678000195')
            ->assertJsonPath('production.uf', 'SP')
            ->assertJsonPath('production.app_id', $application->id)
            ->assertJsonPath('production.app_slug', 'cutinapp')
            ->assertJsonPath('production.user_id', $user->id)
            ->assertJsonPath('production.website_url', 'https://cutinapp.petertecnet.com.br')
            ->assertJsonPath('production.instagram_url', 'https://instagram.com/cutinapp');

        $productionId = (int) $create->json('production.id');
        $this->assertGreaterThan(0, $productionId);

        $this->assertDatabaseHas('productions', [
            'id' => $productionId,
            'app_id' => $application->id,
            'app_slug' => 'cutinapp',
            'user_id' => $user->id,
            'name' => 'Produção Fluxo Completo',
            'cnpj' => '12345678000195',
            'uf' => 'SP',
        ]);

        $this->assertDatabaseHas('application_user', [
            'application_id' => $application->id,
            'user_id' => $user->id,
            'role' => 'producer',
            'status' => 'active',
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/productions/mine')
            ->assertOk()
            ->assertJsonCount(1, 'productions')
            ->assertJsonPath('productions.0.id', $productionId)
            ->assertJsonPath('productions.0.name', 'Produção Fluxo Completo');

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/productions/{$productionId}")
            ->assertOk()
            ->assertJsonPath('production.id', $productionId)
            ->assertJsonPath('production.name', 'Produção Fluxo Completo');

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/productions/{$productionId}", [
                'name' => 'Produção Fluxo Editada',
                'city' => 'Campinas',
                'uf' => 'SP',
                'instagram_url' => 'cutinapp.eventos',
            ])
            ->assertOk()
            ->assertJsonPath('production.name', 'Produção Fluxo Editada')
            ->assertJsonPath('production.city', 'Campinas')
            ->assertJsonPath('production.instagram_url', 'https://instagram.com/cutinapp.eventos')
            ->assertJsonPath('production.app_id', $application->id);

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/productions/{$productionId}")
            ->assertOk()
            ->assertJsonPath('production.name', 'Produção Fluxo Editada')
            ->assertJsonPath('production.city', 'Campinas');

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/productions/mine')
            ->assertOk()
            ->assertJsonPath('productions.0.name', 'Produção Fluxo Editada');
    }

    public function test_production_validation_returns_the_real_field_error(): void
    {
        $user = $this->user('Validação', 'validation@cutinapp.test');

        $this->withHeaders($this->headersFor($user))
            ->postJson('/api/cutinapp/productions', [
                'name' => '',
                'uf' => 'SAO',
                'cnpj' => '123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['name', 'uf', 'cnpj']]);
    }

    public function test_production_from_another_application_is_not_visible_or_editable_in_cutinapp(): void
    {
        $user = $this->user('Isolamento', 'isolation@cutinapp.test');
        $headers = $this->headersFor($user);
        $otherApp = Application::query()->firstOrCreate(
            ['slug' => 'other-app'],
            ['name' => 'Other App', 'is_active' => true]
        );

        $other = Production::create([
            'app_id' => $otherApp->id,
            'app_slug' => 'other-app',
            'user_id' => $user->id,
            'name' => 'Produção de Outro App',
            'slug' => 'producao-outro-app',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/productions/mine')
            ->assertOk()
            ->assertJsonCount(0, 'productions');

        $this->withHeaders($headers)
            ->getJson("/api/cutinapp/productions/{$other->id}")
            ->assertNotFound();

        $this->withHeaders($headers)
            ->postJson("/api/cutinapp/productions/{$other->id}", ['name' => 'Tentativa indevida'])
            ->assertNotFound();
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
