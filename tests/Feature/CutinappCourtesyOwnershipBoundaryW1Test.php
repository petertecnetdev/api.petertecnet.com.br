<?php

namespace Tests\Feature;

use App\Models\EventPass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCourtesyOwnershipBoundaryW1Test extends TestCase
{
    use RefreshDatabase;

    public function test_curated_pending_rejected_and_unknown_provenance_never_issue_courtesy_or_qr(): void
    {
        [$owner, $participant, $productionId, $eventId, $ticketId] = $this->fixture('denied');

        foreach ([
            ['unclaimed', 'curated', 'pending'],
            ['pending_claim', 'curated', 'pending'],
            ['rejected', 'curated', 'rejected'],
            [null, null, null],
        ] as [$ownership, $source, $verification]) {
            $this->setProvenance($productionId, $eventId, $ownership, $source, $verification, $owner->id);

            $this->withHeaders($this->headersFor($participant))
                ->postJson('/api/cutinapp/passes/claim/'.$ticketId)
                ->assertForbidden();

            $this->assertDatabaseCount('event_passes', 0);
            $this->assertSame(0, DB::table('app_notifications')->where('type', 'ticket_issued')->count());
        }
    }

    public function test_explicit_legitimate_legacy_provenance_preserves_free_courtesy_and_idempotency(): void
    {
        [$owner, $participant, $productionId, $eventId, $ticketId] = $this->fixture('legacy');

        $this->setProvenance(
            $productionId,
            $eventId,
            null,
            'legacy_organizer',
            'legacy_owner_record',
            $owner->id
        );

        $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$ticketId)
            ->assertCreated()
            ->assertJsonPath('already_issued', false);

        $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$ticketId)
            ->assertOk()
            ->assertJsonPath('already_issued', true);

        $this->assertDatabaseCount('event_passes', 1);
        $this->assertDatabaseHas('event_passes', ['event_id' => $eventId, 'user_id' => $participant->id]);
    }

    public function test_cross_app_production_never_issues_courtesy(): void
    {
        [$owner, $participant, $productionId, $eventId, $ticketId] = $this->fixture('crossapp');
        $this->setProvenance($productionId, $eventId, null, 'legacy_organizer', 'legacy_owner_record', $owner->id);

        $otherAppId = DB::table('applications')->insertGetId([
            'name' => 'Outra Aplicação Teste',
            'slug' => 'other-courtesy-w1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('establishments')->where('id', $productionId)->update(['app_id' => $otherAppId]);

        $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$ticketId)
            ->assertStatus(422);

        $this->assertDatabaseCount('event_passes', 0);
    }

    public function test_paid_historical_pass_remains_readable_after_listing_becomes_unclaimed(): void
    {
        [$owner, $participant, $productionId, $eventId, $ticketId] = $this->fixture('history');
        $this->setProvenance($productionId, $eventId, 'unclaimed', 'curated', 'pending', $owner->id);

        $paidTicketId = DB::table('tickets')->insertGetId([
            'app_id' => $this->cutinappId(),
            'app_slug' => 'cutinapp',
            'event_id' => $eventId,
            'name' => 'Ingresso pago histórico',
            'quantity' => 10,
            'price' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paidPass = EventPass::create([
            'ticket_id' => $paidTicketId,
            'event_id' => $eventId,
            'user_id' => $participant->id,
            'holder_name' => 'Participante',
            'holder_email' => $participant->email,
            'token' => 'PASS-HISTORICAL-PAID-W1',
            'status' => 'issued',
        ]);

        $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$ticketId)
            ->assertForbidden();

        $this->withHeaders($this->headersFor($participant))
            ->getJson('/api/cutinapp/passes/'.$paidPass->id)
            ->assertOk()
            ->assertJsonPath('pass.id', $paidPass->id)
            ->assertJsonPath('pass.status', 'issued');

        $this->assertDatabaseCount('event_passes', 1);
    }

    private function fixture(string $suffix): array
    {
        $owner = $this->user('Produtor', 'producer-courtesy-'.$suffix.'@cutinapp.test');
        $participant = $this->user('Participante', 'participant-courtesy-'.$suffix.'@cutinapp.test');
        $headers = $this->headersFor($owner);

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Cortesias W1 '.$suffix,
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])->assertCreated()->json('production.id');

        $eventId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => 'Evento Cortesias W1 '.$suffix,
                'description' => 'Evento de teste de titularidade para emissão de cortesias.',
                'address' => 'Rua de Testes, 10',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            ])->assertCreated()->json('event.id');

        $ticketId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $eventId,
                'name' => 'Cortesia W1',
                'quantity' => 10,
            ])->assertCreated()->json('ticket.id');

        $this->withHeaders($headers)
            ->postJson('/api/cutinapp/events/'.$eventId.'/publish')
            ->assertOk();

        return [$owner, $participant, (int) $productionId, (int) $eventId, (int) $ticketId];
    }

    private function setProvenance(
        int $productionId,
        int $eventId,
        ?string $ownership,
        ?string $source,
        ?string $verification,
        int $ownerId
    ): void {
        DB::table('establishments')->where('id', $productionId)->update([
            'ownership_status' => $ownership,
            'listing_source' => $source,
            'verification_status' => $verification,
            'source_url' => null,
            'user_id' => $ownerId,
        ]);
        DB::table('events')->where('id', $eventId)->update([
            'ownership_status' => $ownership,
            'listing_source' => $source,
            'verification_status' => $verification,
            'source_url' => null,
            'created_by_user_id' => $ownerId,
        ]);
    }

    private function cutinappId(): int
    {
        return (int) DB::table('applications')->where('slug', 'cutinapp')->value('id');
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
