<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EventAttendanceNoShowMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ended_event_reports_total_and_paid_no_shows_without_changing_revenue(): void
    {
        [$owner, $appId, $productionId] = $this->eventOwnerContext('ended');
        $eventId = $this->event($appId, $productionId, now()->subHours(4), now()->subHour(), 'ended');
        $paidTicketId = $this->ticket($appId, $eventId, 50.00, 'Inteira');
        $courtesyTicketId = $this->ticket($appId, $eventId, 0.00, 'Cortesia');

        [$orderId, $orderItemId] = $this->paidOrder($appId, $productionId, $eventId, $owner->id, $paidTicketId, 2, 50.00, 10.00);

        $this->pass($eventId, $paidTicketId, $owner->id, 'PAID-NO-SHOW', $orderItemId);
        $this->pass($eventId, $paidTicketId, $owner->id, 'PAID-CHECKED-IN', $orderItemId, 'checked_in', now()->subHours(2));
        $this->pass($eventId, $courtesyTicketId, $owner->id, 'COURTESY-NO-SHOW');
        $this->pass($eventId, $courtesyTicketId, $owner->id, 'REFUNDED-PASS', null, 'refunded');

        $this->withHeaders($this->headersFor($owner))
            ->getJson('/api/v1/apps/cutinapp/checkin/events/'.$eventId.'/stats')
            ->assertOk()
            ->assertJsonPath('attendance_finalized', true)
            ->assertJsonPath('issued', 3)
            ->assertJsonPath('checked_in', 1)
            ->assertJsonPath('no_show', 2)
            ->assertJsonPath('no_show_rate', 66.67)
            ->assertJsonPath('paid', 2)
            ->assertJsonPath('paid_no_show', 1)
            ->assertJsonPath('paid_no_show_rate', 50)
            ->assertJsonPath('paid_no_show_face_value', 50)
            ->assertJsonPath('financial.paid_orders', 1)
            ->assertJsonPath('financial.gross_sales', 100)
            ->assertJsonPath('financial.platform_revenue', 10)
            ->assertJsonPath('financial.producer_net', 90)
            ->assertJsonPath('financial.revenue_recognition', 'paid_order')
            ->assertJsonPath('financial.no_show_extra_fee_applied', false);

        $this->assertDatabaseHas('commerce_orders', [
            'id' => $orderId,
            'status' => 'paid',
            'platform_fee' => 10.00,
            'producer_net' => 90.00,
        ]);
    }

    public function test_unscanned_paid_ticket_is_not_a_no_show_before_event_ends_but_platform_revenue_is_already_counted(): void
    {
        [$owner, $appId, $productionId] = $this->eventOwnerContext('upcoming');
        $eventId = $this->event($appId, $productionId, now()->subHour(), now()->addHours(2), 'upcoming');
        $ticketId = $this->ticket($appId, $eventId, 50.00, 'Inteira');
        [, $orderItemId] = $this->paidOrder($appId, $productionId, $eventId, $owner->id, $ticketId, 1, 50.00, 5.00);
        $this->pass($eventId, $ticketId, $owner->id, 'PAID-NOT-YET-NO-SHOW', $orderItemId);

        $this->withHeaders($this->headersFor($owner))
            ->getJson('/api/v1/apps/cutinapp/checkin/events/'.$eventId.'/stats')
            ->assertOk()
            ->assertJsonPath('attendance_finalized', false)
            ->assertJsonPath('issued', 1)
            ->assertJsonPath('checked_in', 0)
            ->assertJsonPath('no_show', 0)
            ->assertJsonPath('paid', 1)
            ->assertJsonPath('paid_no_show', 0)
            ->assertJsonPath('paid_no_show_face_value', 0)
            ->assertJsonPath('financial.platform_revenue', 5)
            ->assertJsonPath('financial.producer_net', 45);
    }

    private function eventOwnerContext(string $suffix): array
    {
        $owner = User::create([
            'first_name' => 'Produtor '.$suffix,
            'email' => 'producer-'.$suffix.'@no-show.test',
            'user_name' => 'producer-'.$suffix,
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if ($appId <= 0) {
            $appId = DB::table('applications')->insertGetId([
                'name' => 'Cutinapp Test',
                'slug' => 'cutinapp',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $name = 'Produção '.$suffix;
        $productionId = DB::table('establishments')->insertGetId([
            'app_id' => $appId,
            'user_id' => $owner->id,
            'updated_by' => $owner->id,
            'name' => $name,
            'fantasy' => $name,
            'slug' => 'production-'.$suffix.'-'.Str::lower(Str::random(6)),
            'type' => 'production',
            'category' => 'production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $appId, $productionId];
    }

    private function event(int $appId, int $productionId, $start, $end, string $suffix): int
    {
        return DB::table('events')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento '.$suffix,
            'slug' => 'event-'.$suffix.'-'.Str::lower(Str::random(6)),
            'start_date' => $start,
            'end_date' => $end,
            'is_published' => true,
            'is_private' => false,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticket(int $appId, int $eventId, float $price, string $name): int
    {
        return DB::table('tickets')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'event_id' => $eventId,
            'name' => $name,
            'quantity' => 20,
            'price' => $price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function paidOrder(
        int $appId,
        int $productionId,
        int $eventId,
        int $userId,
        int $ticketId,
        int $quantity,
        float $unitPrice,
        float $platformFee,
    ): array {
        $subtotal = $quantity * $unitPrice;
        $orderId = DB::table('commerce_orders')->insertGetId([
            'app_id' => $appId,
            'public_id' => (string) Str::uuid(),
            'event_id' => $eventId,
            'production_id' => $productionId,
            'user_id' => $userId,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => $subtotal,
            'platform_fee' => $platformFee,
            'processor_fee' => 0,
            'discount_amount' => 0,
            'total' => $subtotal,
            'producer_net' => $subtotal - $platformFee,
            'payment_method' => 'pix',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('commerce_order_items')->insertGetId([
            'app_id' => $appId,
            'order_id' => $orderId,
            'type' => 'ticket',
            'ticket_id' => $ticketId,
            'name' => 'Ingresso pago',
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orderId, $itemId];
    }

    private function pass(
        int $eventId,
        int $ticketId,
        int $userId,
        string $token,
        ?int $orderItemId = null,
        string $status = 'issued',
        $checkedInAt = null,
    ): void {
        DB::table('event_passes')->insert([
            'ticket_id' => $ticketId,
            'commerce_order_item_id' => $orderItemId,
            'event_id' => $eventId,
            'user_id' => $userId,
            'holder_name' => 'Participante Teste',
            'holder_email' => 'participant@no-show.test',
            'token' => $token,
            'status' => $status,
            'checked_in_at' => $checkedInAt,
            'checked_in_by' => $checkedInAt ? $userId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }
}
