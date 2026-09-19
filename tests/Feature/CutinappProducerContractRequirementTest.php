<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Event;
use App\Models\ImpersonationSession;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
    public function test_audited_admin_impersonation_can_prepare_draft_but_cannot_publish_without_agreement(): void
    {
        $application = Application::query()->firstOrCreate(
            ['slug' => 'cutinapp'],
            ['name' => 'Cutinapp', 'is_active' => true, 'url' => 'https://cutinapp.petertecnet.com.br']
        );
        $application->forceFill(['is_active' => true, 'url' => 'https://cutinapp.petertecnet.com.br'])->save();

        $admin = User::create([
            'first_name' => 'Peter',
            'email' => 'petertecnet@gmail.com',
            'user_name' => 'peter-admin-assisted',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $producer = User::create([
            'first_name' => 'Produtor assistido',
            'email' => 'assisted-producer@cutinapp.test',
            'user_name' => 'assisted-producer',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => 'cutinapp',
            'user_id' => $producer->id,
            'name' => 'Produção assistida',
            'slug' => 'producao-assistida',
        ]);

        $session = ImpersonationSession::create([
            'uuid' => (string) Str::uuid(),
            'impersonator_user_id' => $admin->id,
            'impersonated_user_id' => $producer->id,
            'application_id' => $application->id,
            'reason' => 'Preparar página e evento antes do handoff ao produtor',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'metadata' => ['source' => 'admincenter'],
        ]);

        $token = JWTAuth::claims([
            'impersonation_session_id' => $session->id,
            'impersonation_uuid' => $session->uuid,
            'actor_user_id' => $admin->id,
            'application_id' => $application->id,
            'impersonated' => true,
        ])->fromUser($producer);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])->postJson('/api/cutinapp/events', [
            'production_id' => $production->id,
            'title' => 'Evento preparado pelo suporte',
            'description' => 'Rascunho criado em modo assistido sem assinar pelo cliente.',
            'address' => 'Rua Teste, 10',
            'city_id' => 5208707,
            'city' => 'Goiânia',
            'uf' => 'GO',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('assisted_setup', true)
            ->assertJsonPath('agreement_required_for_publish', true)
            ->assertJsonPath('event.is_published', false);

        $event = Event::query()->where('title', 'Evento preparado pelo suporte')->firstOrFail();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Peter-App' => 'cutinapp',
        ])->postJson('/api/cutinapp/events/'.$event->id.'/publish')
            ->assertStatus(428);

        $this->assertFalse((bool) $event->fresh()->is_published);
    }

}
