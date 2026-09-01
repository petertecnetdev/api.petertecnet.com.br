<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappInvalidatedPassCheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_refunded_and_charged_back_passes_are_rejected_and_excluded_from_stats(): void
    {
        $owner = $this->user('Owner Invalidated', 'invalidated-owner@cutinapp.test');
        $refundedParticipant = $this->user('Participant Refunded', 'invalidated-refunded@cutinapp.test');
        $chargedBackParticipant = $this->user('Participant Chargeback', 'invalidated-chargeback@cutinapp.test');
        $headers = $this->headersFor($owner);

        $production = $this->withHeaders($headers)->postJson('/api/cutinapp/productions', [
            'name' => 'Produção Passes Invalidados',
            'city' => 'São Paulo',
            'uf' => 'SP',
        ])->assertCreated()->json('production');

        $event = $this->withHeaders($headers)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'],
            'title' => 'Evento Passes Invalidados',
            'description' => 'Evento usado para impedir entrada após estorno ou chargeback.',
            'address' => 'Rua Segurança, 100',
            'city' => 'São Paulo',
            'uf' => 'SP',
            'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_date' => now()->addDay()->addHours(3)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $ticket = $this->withHeaders($headers)->postJson('/api/cutinapp/courtesies', [
            'event_id' => $event['id'],
            'name' => 'Cortesia Segurança',
            'quantity' => 10,
        ])->assertCreated()->json('ticket');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')
            ->assertOk();

        $firstPass = $this->withHeaders($this->headersFor($refundedParticipant))
            ->postJson('/api/cutinapp/passes/claim/' . $ticket['id'])
            ->assertCreated()
            ->json('pass');

        EventPass::query()->whereKey($firstPass['id'])->update(['status' => 'refunded']);

        $secondPass = EventPass::create([
            'ticket_id' => $ticket['id'],
            'event_id' => $event['id'],
            'user_id' => $chargedBackParticipant->id,
            'holder_name' => 'Participant Chargeback',
            'holder_email' => $chargedBackParticipant->email,
            'token' => 'CUT-CHARGEDBACK-TEST',
            'status' => 'charged_back',
        ]);

        $this->travelTo(Carbon::parse($event['start_date'])->addMinute());

        $this->withHeaders($headers)->postJson('/api/cutinapp/checkin', [
            'event_id' => $event['id'],
            'token' => $firstPass['token'],
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Este ingresso foi reembolsado e não pode ser utilizado.');

        $this->withHeaders($headers)->postJson('/api/cutinapp/checkin', [
            'event_id' => $event['id'],
            'token' => $secondPass->token,
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Este ingresso foi invalidado por contestação do pagamento.');

        $this->withHeaders($headers)
            ->getJson('/api/cutinapp/checkin/event/' . $event['id'] . '/stats')
            ->assertOk()
            ->assertJsonPath('issued', 0)
            ->assertJsonPath('checked_in', 0);

        $this->travelBack();
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
        ];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
