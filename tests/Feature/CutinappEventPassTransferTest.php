<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventPassTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_holder_can_transfer_pass_and_old_qr_is_invalidated(): void
    {
        [$producer, $sender, $recipient, $pass] = $this->fixture();
        $oldToken = $pass->token;

        $response = $this->withHeaders($this->headersFor($sender))
            ->postJson('/api/cutinapp/passes/'.$pass->id.'/transfer', [
                'recipient_email' => strtoupper($recipient->email),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Ingresso transferido com sucesso. O QR Code anterior foi invalidado.')
            ->assertJsonPath('transfer.recipient_id', $recipient->id)
            ->assertJsonPath('transfer.recipient_email', $recipient->email);

        $this->assertDatabaseHas('event_passes', [
            'id' => $pass->id,
            'user_id' => $recipient->id,
            'holder_email' => $recipient->email,
        ]);
        $this->assertDatabaseHas('event_pass_transfers', [
            'event_pass_id' => $pass->id,
            'from_user_id' => $sender->id,
            'to_user_id' => $recipient->id,
        ]);

        $recipientPass = $this->withHeaders($this->headersFor($recipient))
            ->getJson('/api/cutinapp/passes/'.$pass->id)
            ->assertOk()
            ->json('pass');

        $this->assertNotSame($oldToken, $recipientPass['token']);
        $this->assertSame($recipient->email, $recipientPass['holder_email']);

        $this->withHeaders($this->headersFor($sender))
            ->getJson('/api/cutinapp/passes/'.$pass->id)
            ->assertForbidden();

        $this->withHeaders($this->headersFor($producer))
            ->postJson('/api/cutinapp/checkin', [
                'event_id' => $pass->event_id,
                'token' => $oldToken,
            ])
            ->assertNotFound()
            ->assertJsonPath('pass', null);

        $this->withHeaders($this->headersFor($producer))
            ->postJson('/api/cutinapp/checkin', [
                'event_id' => $pass->event_id,
                'token' => $recipientPass['token'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Entrada validada com sucesso.');

        $this->assertDatabaseHas('application_user', [
            'application_id' => $this->cutinappId(),
            'user_id' => $recipient->id,
            'status' => 'active',
        ]);

        $this->assertSame($pass->id, (int) $response->json('transfer.pass_id'));
    }

    public function test_used_pass_cannot_be_transferred(): void
    {
        [, $sender, $recipient, $pass] = $this->fixture();
        $pass->forceFill([
            'status' => 'checked_in',
            'checked_in_at' => now(),
        ])->saveQuietly();

        $this->withHeaders($this->headersFor($sender))
            ->postJson('/api/cutinapp/passes/'.$pass->id.'/transfer', [
                'recipient_email' => $recipient->email,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ingressos já utilizados não podem ser transferidos.');

        $this->assertDatabaseHas('event_passes', [
            'id' => $pass->id,
            'user_id' => $sender->id,
        ]);
        $this->assertDatabaseCount('event_pass_transfers', 0);
    }

    public function test_non_holder_cannot_transfer_someone_elses_pass(): void
    {
        [, $sender, $recipient, $pass] = $this->fixture();
        $otherUser = $this->user('Outro', 'outro-transfer@cutinapp.test');

        $this->withHeaders($this->headersFor($otherUser))
            ->postJson('/api/cutinapp/passes/'.$pass->id.'/transfer', [
                'recipient_email' => $recipient->email,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Somente o titular atual pode transferir este ingresso.');

        $this->assertDatabaseHas('event_passes', [
            'id' => $pass->id,
            'user_id' => $sender->id,
        ]);
    }

    private function fixture(): array
    {
        $producer = $this->user('Produtor', 'producer-transfer@cutinapp.test');
        $sender = $this->user('Titular', 'titular-transfer@cutinapp.test');
        $recipient = $this->user('Destino', 'destino-transfer@cutinapp.test');
        $appId = $this->cutinappId();

        $productionId = DB::table('productions')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'user_id' => $producer->id,
            'name' => 'Produção Transferência',
            'city' => 'Goiânia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('events')->insertGetId([
            'app_id' => $appId,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento Transferível',
            'slug' => 'evento-transferivel-'.substr(md5((string) microtime(true)), 0, 8),
            'start_date' => now()->subMinutes(5),
            'end_date' => now()->addHours(3),
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
            'quantity' => 100,
            'price' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pass = EventPass::create([
            'ticket_id' => $ticketId,
            'event_id' => $eventId,
            'user_id' => $sender->id,
            'holder_name' => 'Titular',
            'holder_email' => $sender->email,
            'token' => 'PASS-TRANSFER-OLD-'.substr(md5((string) microtime(true)), 0, 16),
            'status' => 'issued',
        ]);

        return [$producer, $sender, $recipient, $pass];
    }

    private function cutinappId(): int
    {
        $appId = (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if ($appId > 0) {
            return $appId;
        }

        return (int) DB::table('applications')->insertGetId([
            'name' => 'Cutinapp Test',
            'slug' => 'cutinapp',
            'is_active' => true,
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

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower($name).'-'.substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
