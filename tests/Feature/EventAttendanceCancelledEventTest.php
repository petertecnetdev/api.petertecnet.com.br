<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EventAttendanceCancelledEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_ended_event_does_not_generate_no_shows(): void
    {
        $owner = User::create([
            'first_name' => 'Produtor Cancelado',
            'email' => 'cancelled-event@no-show.test',
            'user_name' => 'cancelled-event-owner',
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

        $productionId = DB::table('establishments')->insertGetId([
            'app_id' => $appId,
            'user_id' => $owner->id,
            'updated_by' => $owner->id,
            'name' => 'Produção Cancelada',
            'fantasy' => 'Produção Cancelada',
            'slug' => 'production-cancelled-no-show',
            'type' => 'production',
            'category' => 'production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento Cancelado',
            'slug' => 'evento-cancelado-no-show',
            'start_date' => now()->subHours(4),
            'end_date' => now()->subHour(),
            'is_published' => false,
            'is_private' => false,
            'is_cancelled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('tickets')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'event_id' => $eventId,
            'name' => 'Ingresso',
            'quantity' => 10,
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('event_passes')->insert([
            'ticket_id' => $ticketId,
            'event_id' => $eventId,
            'user_id' => $owner->id,
            'token' => 'CANCELLED-NOT-NO-SHOW',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.JWTAuth::fromUser($owner),
            'X-Peter-App' => 'cutinapp',
        ])->getJson('/api/v1/apps/cutinapp/checkin/events/'.$eventId.'/stats')
            ->assertOk()
            ->assertJsonPath('attendance_finalized', false)
            ->assertJsonPath('issued', 1)
            ->assertJsonPath('checked_in', 0)
            ->assertJsonPath('no_show', 0)
            ->assertJsonPath('no_show_rate', 0);
    }
}
