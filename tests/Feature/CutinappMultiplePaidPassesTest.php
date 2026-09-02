<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CutinappMultiplePaidPassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_buyer_can_hold_multiple_passes_from_same_ticket_lot(): void
    {
        $user = User::create([
            'first_name' => 'Comprador',
            'email' => 'multiple-passes@cutinapp.test',
            'user_name' => 'multiple-passes',
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

        $productionId = DB::table('productions')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'user_id' => $user->id,
            'name' => 'Produção Teste',
            'city' => 'São Paulo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento Teste',
            'slug' => 'evento-multiple-passes',
            'start_date' => now()->subHour(),
            'end_date' => now()->addHours(4),
            'is_published' => true,
            'is_private' => false,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = DB::table('tickets')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'event_id' => $eventId,
            'name' => 'Ingresso Inteira',
            'quantity' => 10,
            'price' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        EventPass::create([
            'ticket_id' => $ticketId,
            'event_id' => $eventId,
            'user_id' => $user->id,
            'token' => 'CUT-MULTIPLE-1',
            'status' => 'issued',
        ]);

        EventPass::create([
            'ticket_id' => $ticketId,
            'event_id' => $eventId,
            'user_id' => $user->id,
            'token' => 'CUT-MULTIPLE-2',
            'status' => 'issued',
        ]);

        $this->assertSame(2, EventPass::query()
            ->where('ticket_id', $ticketId)
            ->where('user_id', $user->id)
            ->count());
    }
}
