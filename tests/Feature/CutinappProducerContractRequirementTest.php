<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappProducerContractRequirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_production_cannot_create_event(): void
    {
        $application = Application::query()->firstOrCreate(
            ['slug' => 'cutinapp'],
            ['name' => 'Cutinapp', 'is_active' => true]
        );

        $user = User::create([
            'first_name' => 'Produtor sem contrato',
            'email' => 'unsigned-contract@cutinapp.test',
            'user_name' => 'unsigned-contract',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => 'cutinapp',
            'user_id' => $user->id,
            'name' => 'Produção sem contrato',
            'slug' => 'producao-sem-contrato',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
            'X-Test-Unsigned-Contract' => '1',
        ])->postJson('/api/cutinapp/events', [
            'production_id' => $production->id,
            'title' => 'Evento bloqueado',
            'description' => 'Este evento não pode existir antes da assinatura.',
            'address' => 'Rua Teste, 1',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(428);

        $this->assertDatabaseMissing('events', ['title' => 'Evento bloqueado']);
    }
}
