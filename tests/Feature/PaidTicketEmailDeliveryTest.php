<?php

namespace Tests\Feature;

use App\Mail\EventPassesMail;
use App\Models\Establishment;
use App\Models\User;
use App\Services\EventAudienceService;
use App\Services\PaidTicketEmailRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaidTicketEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_email_is_sent_once_and_never_before_all_passes_exist(): void
    {
        Mail::fake();
        [$orderId, $passDetails] = $this->createPaidOrder(2, 1);

        $this->assertNull(app(EventAudienceService::class)->deliverPaidTicketEmail($orderId));
        Mail::assertNotSent(EventPassesMail::class);

        $this->createPass($passDetails, 2);

        $this->assertTrue(app(EventAudienceService::class)->deliverPaidTicketEmail($orderId));
        $this->assertFalse(app(EventAudienceService::class)->deliverPaidTicketEmail($orderId));
        Mail::assertSent(EventPassesMail::class, 1);
        $this->assertSame('completed', DB::table('delivery_effects')
            ->where('aggregate_id', (string) $orderId)
            ->where('effect_key', 'buyer_event_passes_email')
            ->value('status'));
    }

    public function test_failed_email_is_retried_without_reissuing_passes(): void
    {
        [$orderId] = $this->createPaidOrder();

        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Falha SMTP simulada'));
        $this->assertFalse(app(EventAudienceService::class)->deliverPaidTicketEmail($orderId));
        $this->assertSame('failed', DB::table('delivery_effects')
            ->where('aggregate_id', (string) $orderId)
            ->where('effect_key', 'buyer_event_passes_email')
            ->value('status'));

        Mail::swap($originalMailer);
        Mail::fake();
        DB::table('delivery_effects')
            ->where('aggregate_id', (string) $orderId)
            ->update(['updated_at' => now()->subMinutes(30)]);

        $result = app(PaidTicketEmailRecoveryService::class)->run(10, 15, 'cutinapp');
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        Mail::assertSent(EventPassesMail::class, 1);
        $this->assertSame(1, DB::table('event_passes')->where('commerce_order_item_id', $this->orderItemId($orderId))->count());

        $next = app(PaidTicketEmailRecoveryService::class)->run(10, 15, 'cutinapp');
        $this->assertSame(0, $next['sent']);
        Mail::assertSent(EventPassesMail::class, 1);
    }

    private function createPaidOrder(int $quantity = 1, int $passCount = 1): array
    {
        $user = User::create([
            'first_name' => 'Participante',
            'last_name' => 'Teste',
            'email' => 'comprador@example.test',
            'user_name' => 'comprador-email-'.uniqid(),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if ($appId === 0) {
            $appId = DB::table('applications')->insertGetId([
                'name' => 'Cutinapp',
                'slug' => 'cutinapp',
                'url' => 'https://cutinapp.example.test',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productionId = Establishment::query()->create([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'user_id' => $user->id,
            'name' => 'Produção de Teste',
            'fantasy' => 'Produção de Teste',
            'category' => 'production',
            'type' => 'production',
            'establishment_type' => 'production',
            'city' => 'Goiânia',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ])->id;

        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento de Teste',
            'slug' => 'evento-de-teste-email',
            'start_date' => now()->addDays(7),
            'end_date' => now()->addDays(7)->addHours(3),
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('tickets')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'event_id' => $eventId,
            'name' => 'Inteira',
            'price' => 20,
            'quantity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('commerce_orders')->insertGetId([
            'app_id' => $appId,
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_id' => $eventId,
            'production_id' => $productionId,
            'user_id' => $user->id,
            'status' => 'paid',
            'currency' => 'BRL',
            'subtotal' => 20 * $quantity,
            'total' => 20 * $quantity,
            'paid_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $itemId = DB::table('commerce_order_items')->insertGetId([
            'app_id' => $appId,
            'order_id' => $orderId,
            'type' => 'ticket',
            'ticket_id' => $ticketId,
            'name' => 'Inteira',
            'unit_price' => 20,
            'quantity' => $quantity,
            'subtotal' => $quantity * 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $details = compact('eventId', 'ticketId', 'itemId', 'user');

        for ($i = 1; $i <= $passCount; $i++) {
            $this->createPass($details, $i);
        }

        return [$orderId, $details];
    }

    private function createPass(array $data, int $number): void
    {
        DB::table('event_passes')->insert([
            'event_id' => $data['eventId'],
            'ticket_id' => $data['ticketId'],
            'commerce_order_item_id' => $data['itemId'],
            'user_id' => $data['user']->id,
            'holder_name' => 'Participante Teste',
            'token' => 'TESTE-INGRESSO-'.$number,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderItemId(int $orderId): int
    {
        return (int) DB::table('commerce_order_items')->where('order_id', $orderId)->value('id');
    }
}
