<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CutinappDiscoveryFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_city_uf_today_tomorrow_weekend_and_combined_filters_are_server_side(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 31, 19, 0, 0, 'America/Sao_Paulo')); // segunda-feira
        [$app, $production] = $this->base();

        $today = $this->event($app->id, $production->id, 'Hoje BH', 'Belo Horizonte', 'MG', '2026-08-31 21:00:00');
        $tomorrow = $this->event($app->id, $production->id, 'Amanhã BH', 'Belo Horizonte', 'MG', '2026-09-01 20:00:00');
        $friday = $this->event($app->id, $production->id, 'Sexta BH', 'Belo Horizonte', 'MG', '2026-09-04 22:00:00');
        $saturday = $this->event($app->id, $production->id, 'Sábado BH', 'Belo Horizonte', 'MG', '2026-09-05 23:00:00');
        $this->event($app->id, $production->id, 'Sábado SP', 'São Paulo', 'SP', '2026-09-05 22:00:00');

        $this->getJson('/api/cutinapp/events?city=Belo%20Horizonte&uf=MG&period=today')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $today->id);

        $this->getJson('/api/cutinapp/events?city=Belo%20Horizonte&period=tomorrow')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $tomorrow->id);

        $weekend = $this->getJson('/api/cutinapp/events?city=Belo%20Horizonte&period=weekend')
            ->assertOk()->json('events.data');
        $this->assertSame([$friday->id, $saturday->id], array_column($weekend, 'id'));

        $this->getJson('/api/cutinapp/events?city=Belo%20Horizonte&period=saturday')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $saturday->id);

        $this->getJson('/api/cutinapp/events?uf=SP&period=weekend')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.city', 'São Paulo');
    }

    public function test_custom_ranges_pagination_closed_events_and_available_tickets(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 31, 19, 0, 0, 'America/Sao_Paulo'));
        [$app, $production, $user] = $this->base();

        $available = $this->event($app->id, $production->id, 'Disponível', 'Campinas', 'SP', '2026-09-05 18:00:00');
        $ticket = Ticket::create([
            'app_id' => $app->id, 'app_slug' => 'cutinapp', 'event_id' => $available->id,
            'name' => 'Cortesia', 'ticket_type' => 'courtesy', 'type' => 'courtesy', 'price' => 0,
            'quantity' => 10, 'limit_date' => Carbon::create(2026, 9, 5, 17, 0, 0, 'America/Sao_Paulo'),
        ]);
        $this->event($app->id, $production->id, 'Sem ingresso', 'Campinas', 'SP', '2026-09-06 18:00:00');

        Event::withoutEvents(function () use ($app, $production) {
            Event::create([
                'app_id' => $app->id, 'app_slug' => 'cutinapp', 'production_id' => $production->id,
                'title' => 'Encerrado', 'slug' => 'encerrado-discovery', 'description' => 'Passado',
                'address' => 'Rua Antiga', 'city' => 'Campinas', 'uf' => 'SP',
                'start_date' => '2026-08-29 18:00:00', 'end_date' => '2026-08-29 22:00:00',
                'is_published' => true, 'is_cancelled' => false,
            ]);
        });

        $orderId = DB::table('commerce_orders')->insertGetId([
            'app_id' => $app->id,
            'public_id' => (string) Str::uuid(),
            'event_id' => $available->id,
            'production_id' => $production->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'currency' => 'BRL',
            'subtotal' => 0,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'producer_net' => 0,
            'expires_at' => now()->addMinutes(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_reservations')->insert([
            'app_id' => $app->id,
            'order_id' => $orderId,
            'type' => 'ticket',
            'ticket_id' => $ticket->id,
            'quantity' => 10,
            'expires_at' => now()->addMinutes(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/cutinapp/events?city=Campinas&available=1&free=1')
            ->assertOk()->assertJsonCount(0, 'events.data');
        $this->getJson('/api/cutinapp/events/public/'.$available->slug)
            ->assertOk()->assertJsonPath('tickets.0.remaining', 0)->assertJsonPath('tickets.0.available', false);

        DB::table('inventory_reservations')->where('order_id', $orderId)->update([
            'expires_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        Cache::flush();

        $this->getJson('/api/cutinapp/events?city=Campinas&available=1&free=1')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $available->id);

        $this->getJson('/api/cutinapp/events?city=Campinas&period=custom&from=2026-09-05&to=2026-09-05')
            ->assertOk()->assertJsonCount(1, 'events.data')->assertJsonPath('events.data.0.id', $available->id);

        $this->getJson('/api/cutinapp/events?city=Campinas&per_page=1&page=1')
            ->assertOk()->assertJsonPath('events.per_page', 1)->assertJsonMissing(['title' => 'Encerrado']);

        $this->getJson('/api/cutinapp/events?period=qualquer-coisa')->assertStatus(422);
        $this->getJson('/api/cutinapp/events?from=2026-09-10&to=2026-09-01&period=custom')->assertStatus(422);
    }

    private function base(): array
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $user = User::create([
            'first_name' => 'Produtor Discovery', 'email' => 'discovery@cutinapp.test',
            'user_name' => 'discovery-user', 'password' => Hash::make('Test1234!'), 'email_verified_at' => now(),
        ]);
        $production = Production::create([
            'app_id' => $app->id, 'app_slug' => 'cutinapp', 'user_id' => $user->id,
            'name' => 'Discovery Produções', 'slug' => 'discovery-producoes', 'is_published' => true, 'is_cancelled' => false,
        ]);
        return [$app, $production, $user];
    }

    private function event(int $appId, int $productionId, string $title, string $city, string $uf, string $start): Event
    {
        $startAt = Carbon::parse($start, 'America/Sao_Paulo');
        return Event::create([
            'app_id' => $appId, 'app_slug' => 'cutinapp', 'production_id' => $productionId,
            'title' => $title, 'slug' => strtolower(str_replace([' ', 'á', 'ã'], ['-', 'a', 'a'], $title)) . '-' . md5($title),
            'description' => 'Evento para teste de descoberta.', 'address' => 'Rua Teste, 1',
            'city' => $city, 'uf' => $uf, 'category' => 'Música',
            'start_date' => $startAt, 'end_date' => $startAt->copy()->addHours(4),
            'is_published' => true, 'is_cancelled' => false,
        ]);
    }
}
