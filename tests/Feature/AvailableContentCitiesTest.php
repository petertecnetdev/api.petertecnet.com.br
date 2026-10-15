<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AvailableContentCitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_cities_with_public_productions_in_the_application_are_available(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $user = User::create([
            'first_name' => 'Teste Cidades',
            'email' => 'cidades-test@example.test',
            'user_name' => 'teste-cidades',
            'password' => Hash::make('Test1234!'),
        ]);

        foreach ([
            ['Belo Horizonte', 'MG', true, false],
            ['São Paulo', 'SP', true, false],
            ['São Paulo', 'SP', true, false],
            ['Recife', 'PE', false, false],
            ['Curitiba', 'PR', true, true],
        ] as $index => [$city, $uf, $published, $cancelled]) {
            Production::create([
                'app_id' => $app->id,
                'app_slug' => 'cutinapp',
                'user_id' => $user->id,
                'name' => 'Produção '.$index,
                'slug' => 'producao-cidades-'.$index,
                'city' => $city,
                'uf' => $uf,
                'is_published' => $published,
                'is_cancelled' => $cancelled,
            ]);
        }

        $response = $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?type=productions')
            ->assertOk()
            ->assertJsonCount(2, 'cities');
        $this->assertEqualsCanonicalizing(
            [['city' => 'Belo Horizonte', 'uf' => 'MG', 'total' => 1], ['city' => 'São Paulo', 'uf' => 'SP', 'total' => 2]],
            $response->json('cities')
        );

        $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?type=productions&q=Paulo')
            ->assertOk()->assertJsonCount(1, 'cities')->assertJsonPath('cities.0.city', 'São Paulo');
        $this->getJson('/api/v1/apps/cutinapp/locations/available-cities?type=invalid')
            ->assertUnprocessable();
    }
}
