<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\EventSchedule;
use App\Models\Item;
use App\Models\Profile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventSeriesCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_import_establishment_catalog_with_event_specific_price_and_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Sao_Paulo'));
        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('catalog-owner@example.test', 'catalog-owner');
        $outsider = $this->user('catalog-outsider@example.test', 'catalog-outsider', false);
        $productionId = $this->production($app->id, $owner->id, 'catalog-production');
        $otherProductionId = $this->production($app->id, $owner->id, 'other-production');
        $event = $this->event($app->id, $productionId, 'catalog-event', '2026-09-12 20:00:00', '2026-09-13 02:00:00');

        $source = Item::create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'entity_name' => 'establishment',
            'entity_id' => $productionId,
            'name' => 'Combo da casa',
            'description' => 'Bebida e acompanhamento',
            'type' => 'product',
            'price' => 45,
            'stock' => 30,
            'status' => true,
            'category' => 'Combos',
        ]);
        $foreign = Item::create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'entity_name' => 'establishment',
            'entity_id' => $otherProductionId,
            'name' => 'Produto de outro local',
            'type' => 'product',
            'price' => 10,
            'stock' => 5,
            'status' => true,
        ]);

        $ownerToken = auth('api')->login($owner);
        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/apps/cutinapp/events/'.$event->id.'/catalog-items', [
                'replace' => true,
                'items' => [[
                    'item_id' => $source->id,
                    'price' => 39.90,
                    'quantity' => 12,
                    'is_active' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('selected_count', 1)
            ->assertJsonPath('catalog.0.item_id', $source->id)
            ->assertJsonPath('catalog.0.event_item.price', 39.9)
            ->assertJsonPath('catalog.0.event_item.quantity', 12);

        $this->assertDatabaseHas('event_items', [
            'app_id' => $app->id,
            'event_id' => $event->id,
            'source_item_id' => $source->id,
            'name' => 'Combo da casa',
            'quantity' => 12,
            'is_active' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/apps/cutinapp/events/'.$event->id.'/catalog-items', [
                'replace' => true,
                'items' => [[
                    'item_id' => $foreign->id,
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(422);

        $outsiderToken = auth('api')->login($outsider);
        $this->withHeader('Authorization', 'Bearer '.$outsiderToken)
            ->getJson('/api/v1/apps/cutinapp/events/'.$event->id.'/catalog-items')
            ->assertForbidden();
    }

    public function test_manual_duplicate_keeps_logical_series_and_independent_inventory(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Sao_Paulo'));
        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('series-owner@example.test', 'series-owner');
        $productionId = $this->production($app->id, $owner->id, 'series-production');

        $schedule = EventSchedule::create([
            'app_id' => $app->id,
            'production_id' => $productionId,
            'title' => 'Sábado fixo',
            'description' => 'Evento recorrente',
            'day_of_week' => 6,
            'start_time' => '20:00',
            'end_time' => '02:00',
            'is_active' => true,
        ]);
        $this->assertNotEmpty($schedule->event_series_id);

        $source = $this->event($app->id, $productionId, 'series-source', '2026-09-12 20:00:00', '2026-09-13 02:00:00');
        $source->forceFill([
            'event_schedule_id' => $schedule->id,
            'event_schedule_occurrence_date' => '2026-09-12',
        ])->saveQuietly();
        $this->assertSame($schedule->event_series_id, $source->fresh()->event_series_id);

        Ticket::withoutEvents(fn () => Ticket::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'event_id' => $source->id,
            'name' => 'Ingresso',
            'type' => 'paid',
            'ticket_type' => 'standard',
            'price' => 20,
            'quantity' => 100,
            'limit_date' => '2026-09-12 19:00:00',
        ]));
        EventItem::create([
            'app_id' => $app->id,
            'event_id' => $source->id,
            'name' => 'Balde',
            'price' => 50,
            'quantity' => 10,
            'is_active' => true,
        ]);

        $ownerToken = auth('api')->login($owner);
        $response = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/v1/apps/cutinapp/events/'.$source->id.'/duplicate', [
                'date' => '2026-09-19',
            ])
            ->assertCreated()
            ->assertJsonPath('copied.tickets', 1)
            ->assertJsonPath('copied.items', 1);

        $duplicateId = (int) $response->json('event.id');
        $duplicate = Event::findOrFail($duplicateId);
        $source->refresh();

        $this->assertSame($source->event_series_id, $duplicate->event_series_id);
        $this->assertNull($duplicate->event_schedule_id);
        $this->assertNull($duplicate->event_schedule_occurrence_date);
        $this->assertFalse((bool) $duplicate->is_published);
        $this->assertDatabaseHas('event_items', [
            'event_id' => $duplicate->id,
            'name' => 'Balde',
            'quantity' => 10,
            'is_active' => 1,
        ]);

        EventItem::query()->where('event_id', $duplicate->id)->update(['quantity' => 3]);
        $this->assertDatabaseHas('event_items', ['event_id' => $source->id, 'name' => 'Balde', 'quantity' => 10]);
        $this->assertDatabaseHas('event_items', ['event_id' => $duplicate->id, 'name' => 'Balde', 'quantity' => 3]);

        $duplicate->forceFill(['is_published' => true])->saveQuietly();
        $this->getJson('/api/v1/apps/cutinapp/events/public/'.$source->slug.'/purchase-options')
            ->assertOk()
            ->assertJsonCount(2, 'available_dates')
            ->assertJsonPath('available_dates.0.series_id', $source->event_series_id)
            ->assertJsonPath('available_dates.1.series_id', $source->event_series_id);
    }

    public function test_all_active_catalog_import_uses_real_catalog_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Sao_Paulo'));
        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('all-catalog@example.test', 'all-catalog');
        $productionId = $this->production($app->id, $owner->id, 'all-catalog-production');
        $event = $this->event($app->id, $productionId, 'all-catalog-event', '2026-09-20 18:00:00', '2026-09-20 23:00:00', 250);

        Item::create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'entity_name' => 'establishment',
            'entity_id' => $productionId,
            'name' => 'Camiseta limitada',
            'type' => 'product',
            'price' => 80,
            'stock' => 15,
            'status' => true,
        ]);
        Item::create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'entity_name' => 'establishment',
            'entity_id' => $productionId,
            'name' => 'Drink esgotado',
            'type' => 'product',
            'price' => 25,
            'stock' => 0,
            'status' => true,
        ]);

        $token = auth('api')->login($owner);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/apps/cutinapp/events/'.$event->id.'/catalog-items', [
                'all_active' => true,
                'replace' => true,
            ])
            ->assertOk()
            ->assertJsonPath('selected_count', 2);

        $this->assertDatabaseHas('event_items', [
            'event_id' => $event->id,
            'name' => 'Camiseta limitada',
            'quantity' => 15,
        ]);
        $this->assertDatabaseHas('event_items', [
            'event_id' => $event->id,
            'name' => 'Drink esgotado',
            'quantity' => 0,
        ]);
    }

    private function user(string $email, string $username, bool $admin = true): User
    {
        $profile = Profile::create([
            'name' => $admin ? 'Administrador' : 'Participante-'.$username,
            'permissions' => [],
        ]);

        return User::create([
            'first_name' => $admin ? 'Owner' : 'User',
            'email' => $email,
            'user_name' => $username,
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function production(int $appId, int $userId, string $slug): int
    {
        return DB::table('establishments')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'name' => Str::headline($slug),
            'slug' => $slug,
            'type' => 'production',
            'category' => 'production',
            'establishment_type' => 'production',
            'user_id' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(int $appId, int $productionId, string $slug, string $start, string $end, ?int $capacity = null): Event
    {
        return Event::withoutEvents(fn () => Event::create([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => Str::headline($slug),
            'description' => 'Evento de teste',
            'event_format' => 'in_person',
            'address' => 'Rua Teste, 100',
            'start_date' => $start,
            'end_date' => $end,
            'max_attendees' => $capacity,
            'slug' => $slug,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'is_private' => false,
        ]));
    }
}
