<?php

namespace Tests\Feature;

use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Event;
use App\Models\EventItem;
use App\Models\Profile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventDateItemRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_purchase_options_group_published_occurrences_and_keep_inventory_scoped_to_selected_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Sao_Paulo'));

        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('owner-dates@example.test', 'owner-dates');
        $productionId = $this->production($app->id, $owner->id, 'agenda-dates');
        $scheduleId = DB::table('event_schedules')->insertGetId([
            'app_id' => $app->id,
            'production_id' => $productionId,
            'title' => 'Sexta fixa',
            'description' => 'Agenda semanal de teste',
            'day_of_week' => 5,
            'start_time' => '20:00:00',
            'end_time' => '23:59:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $this->event($app->id, $productionId, 'sexta-12', '2026-09-12 20:00:00', '2026-09-12 23:59:00');
        $first->forceFill([
            'event_schedule_id' => $scheduleId,
            'event_schedule_occurrence_date' => '2026-09-12',
        ])->saveQuietly();

        $second = $this->event($app->id, $productionId, 'sexta-19', '2026-09-19 20:00:00', '2026-09-19 23:59:00');
        $second->forceFill([
            'event_schedule_id' => $scheduleId,
            'event_schedule_occurrence_date' => '2026-09-19',
        ])->saveQuietly();

        Ticket::withoutEvents(fn () => Ticket::create([
            'app_id' => $app->id,
            'event_id' => $first->id,
            'name' => 'Ingresso 12/09',
            'type' => 'paid',
            'ticket_type' => 'paid',
            'price' => 25,
            'quantity' => 10,
            'limit_date' => '2026-09-12 18:00:00',
            'description' => 'Ingresso da primeira data',
        ]));

        Ticket::withoutEvents(fn () => Ticket::create([
            'app_id' => $app->id,
            'event_id' => $second->id,
            'name' => 'Ingresso 19/09',
            'type' => 'paid',
            'ticket_type' => 'paid',
            'price' => 30,
            'quantity' => 20,
            'limit_date' => '2026-09-19 18:00:00',
            'description' => 'Ingresso da segunda data',
        ]));

        EventItem::create([
            'app_id' => $app->id,
            'event_id' => $first->id,
            'name' => 'Combo primeira data',
            'description' => 'Retirada no evento',
            'price' => 40,
            'quantity' => 5,
            'is_active' => true,
        ]);

        EventItem::create([
            'app_id' => $app->id,
            'event_id' => $second->id,
            'name' => 'Combo segunda data',
            'description' => 'Retirada no evento',
            'price' => 45,
            'quantity' => 7,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/apps/cutinapp/events/public/sexta-12/purchase-options');

        $response->assertOk()
            ->assertJsonPath('event.id', $first->id)
            ->assertJsonCount(2, 'available_dates')
            ->assertJsonPath('available_dates.0.event_id', $first->id)
            ->assertJsonPath('available_dates.1.event_id', $second->id)
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.name', 'Ingresso 12/09')
            ->assertJsonPath('tickets.0.remaining', 10)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Combo primeira data')
            ->assertJsonPath('items.0.remaining', 5)
            ->assertJsonMissing(['name' => 'Ingresso 19/09'])
            ->assertJsonMissing(['name' => 'Combo segunda data']);
    }

    public function test_paid_item_order_gets_one_time_pickup_qr_and_cannot_be_redeemed_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 20:00:00', 'America/Sao_Paulo'));

        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('owner-pickup@example.test', 'owner-pickup');
        $buyer = $this->user('buyer-pickup@example.test', 'buyer-pickup', false);
        $productionId = $this->production($app->id, $owner->id, 'pickup-production');
        $event = $this->event($app->id, $productionId, 'pickup-event', '2026-09-05 18:00:00', '2026-09-05 23:59:00');
        $eventItem = EventItem::create([
            'app_id' => $app->id,
            'event_id' => $event->id,
            'name' => 'Balde promocional',
            'description' => 'Pré-venda',
            'price' => 50,
            'quantity' => 20,
            'is_active' => true,
        ]);

        $order = CommerceOrder::create([
            'app_id' => $app->id,
            'public_id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'production_id' => $productionId,
            'user_id' => $buyer->id,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => 100,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 100,
            'producer_net' => 100,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        CommerceOrderItem::create([
            'app_id' => $app->id,
            'order_id' => $order->id,
            'type' => 'item',
            'event_item_id' => $eventItem->id,
            'name' => 'Balde promocional',
            'unit_price' => 50,
            'quantity' => 2,
            'subtotal' => 100,
            'metadata' => [],
        ]);

        $buyerToken = auth('api')->login($buyer);
        $credentialResponse = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/apps/cutinapp/commerce/orders/'.$order->public_id.'/pickup-credential');

        $credentialResponse->assertOk()
            ->assertJsonPath('credential.status', 'active')
            ->assertJsonPath('credential.event.id', $event->id)
            ->assertJsonPath('credential.items.0.name', 'Balde promocional')
            ->assertJsonPath('credential.items.0.quantity', 2);

        $token = (string) $credentialResponse->json('credential.token');
        $this->assertStringStartsWith('ITEM-'.$order->public_id.'.', $token);

        $ownerToken = auth('api')->login($owner);
        $redeem = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/v1/apps/cutinapp/commerce/item-redemptions/redeem', [
                'token' => $token,
                'event_id' => $event->id,
            ]);

        $redeem->assertOk()
            ->assertJsonPath('status', 'redeemed')
            ->assertJsonPath('items.0.name', 'Balde promocional')
            ->assertJsonPath('items.0.quantity', 2);

        $this->assertDatabaseHas('commerce_order_redemptions', [
            'app_id' => $app->id,
            'order_id' => $order->id,
            'event_id' => $event->id,
            'status' => 'redeemed',
            'redeemed_by_user_id' => $owner->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/v1/apps/cutinapp/commerce/item-redemptions/redeem', [
                'token' => $token,
                'event_id' => $event->id,
            ])
            ->assertStatus(409);
    }

    public function test_item_pickup_qr_is_blocked_before_event_day_and_for_non_owner_operator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Sao_Paulo'));

        $app = $this->applicationFixture('cutinapp', ['name' => 'Cutinapp', 'is_active' => true]);
        $owner = $this->user('owner-security@example.test', 'owner-security');
        $buyer = $this->user('buyer-security@example.test', 'buyer-security', false);
        $outsider = $this->user('outsider-security@example.test', 'outsider-security', false);
        $productionId = $this->production($app->id, $owner->id, 'security-production');
        $event = $this->event($app->id, $productionId, 'security-event', '2026-09-06 18:00:00', '2026-09-06 23:59:00');
        $eventItem = EventItem::create([
            'app_id' => $app->id,
            'event_id' => $event->id,
            'name' => 'Produto seguro',
            'price' => 30,
            'quantity' => 10,
            'is_active' => true,
        ]);

        $order = CommerceOrder::create([
            'app_id' => $app->id,
            'public_id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'production_id' => $productionId,
            'user_id' => $buyer->id,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => 30,
            'platform_fee' => 0,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => 30,
            'producer_net' => 30,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'metadata' => [],
        ]);

        CommerceOrderItem::create([
            'app_id' => $app->id,
            'order_id' => $order->id,
            'type' => 'item',
            'event_item_id' => $eventItem->id,
            'name' => 'Produto seguro',
            'unit_price' => 30,
            'quantity' => 1,
            'subtotal' => 30,
            'metadata' => [],
        ]);

        $buyerToken = auth('api')->login($buyer);
        $token = (string) $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->getJson('/api/v1/apps/cutinapp/commerce/orders/'.$order->public_id.'/pickup-credential')
            ->assertOk()
            ->json('credential.token');

        $outsiderToken = auth('api')->login($outsider);
        $this->withHeader('Authorization', 'Bearer '.$outsiderToken)
            ->postJson('/api/v1/apps/cutinapp/commerce/item-redemptions/redeem', [
                'token' => $token,
                'event_id' => $event->id,
            ])
            ->assertForbidden();

        $ownerToken = auth('api')->login($owner);
        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/v1/apps/cutinapp/commerce/item-redemptions/redeem', [
                'token' => $token,
                'event_id' => $event->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('commerce_order_redemptions', [
            'app_id' => $app->id,
            'order_id' => $order->id,
            'status' => 'redeemed',
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

    private function event(int $appId, int $productionId, string $slug, string $start, string $end): Event
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
            'slug' => $slug,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'is_private' => false,
        ]));
    }
}
