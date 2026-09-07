<?php

namespace Tests\Feature;

use App\Models\AcquisitionReferral;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventAcquisitionCommission;
use App\Models\Production;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AcquisitionCommissionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_commission_cannot_change_after_first_paid_order(): void
    {
        $application = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);
        $profile = Profile::query()->firstOrCreate(['name' => 'Cliente'], ['permissions' => []]);
        $agent = User::create([
            'first_name' => 'Agente',
            'email' => 'agent-lock@example.test',
            'user_name' => 'agent-lock',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
        $producer = User::create([
            'first_name' => 'Produtor',
            'email' => 'producer-lock@example.test',
            'user_name' => 'producer-lock',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
            'email_verified_at' => now(),
        ]);
        $agent->applications()->attach($application->id, [
            'role' => 'producer',
            'status' => 'active',
            'metadata' => json_encode(['roles' => ['producer', 'acquisition_agent']]),
            'joined_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => 'cutinapp',
            'user_id' => $producer->id,
            'name' => 'Produção Comissão',
            'slug' => 'producao-comissao',
            'is_published' => true,
            'is_cancelled' => false,
        ]);
        $event = Event::create([
            'app_id' => $application->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'title' => 'Evento Comissão',
            'slug' => 'evento-comissao',
            'description' => 'Teste de integridade financeira.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/live',
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(5)->addHours(3),
            'is_published' => true,
            'is_cancelled' => false,
        ]);
        $referral = AcquisitionReferral::create([
            'application_id' => $application->id,
            'agent_user_id' => $agent->id,
            'referred_user_id' => $producer->id,
            'production_id' => $production->id,
            'email' => $producer->email,
            'name' => 'Produtor',
            'token_hash' => hash('sha256', 'referral-token-lock'),
            'activation_code_hash' => Hash::make('LOCK1234'),
            'requires_password' => false,
            'status' => 'accepted',
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
        ]);
        EventAcquisitionCommission::create([
            'application_id' => $application->id,
            'agent_user_id' => $agent->id,
            'referral_id' => $referral->id,
            'event_id' => $event->id,
            'percentage' => 10,
            'basis' => 'gross_sales',
        ]);
        CommerceOrder::create([
            'app_id' => $application->id,
            'public_id' => 'ORDER-LOCK-1',
            'event_id' => $event->id,
            'production_id' => $production->id,
            'user_id' => $producer->id,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => 100,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'producer_net' => 100,
            'paid_at' => now(),
        ]);

        $token = auth('api')->login($agent);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/apps/cutinapp/acquisition/events/'.$event->id.'/commission', [
                'percentage' => 7,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('event_acquisition_commissions', [
            'event_id' => $event->id,
            'percentage' => 10.00,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/apps/cutinapp/acquisition/dashboard')
            ->assertOk()
            ->assertJsonPath('commissions.0.commission_locked', true)
            ->assertJsonPath('commissions.0.commission_amount', 10);
    }
}
