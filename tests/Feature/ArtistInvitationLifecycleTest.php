<?php

namespace Tests\Feature;

use App\Domain\People\Services\ArtistInvitationWorkflowService;
use App\Mail\ArtistEventInvitationMail;
use App\Models\Application;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ArtistInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_user_receives_email_and_must_accept_before_confirmation(): void
    {
        Mail::fake();
        [$app, $producer, $artistUser, $event] = $this->fixtures();

        $workflow = app(ArtistInvitationWorkflowService::class);
        $result = $workflow->inviteRegistered($app->id, $event, $producer, $artistUser, [
            'participation_type' => 'show',
            'scheduled_at' => now()->addDays(5)->setTime(22, 0)->format('Y-m-d H:i:s'),
            'stage' => 'Palco principal',
            'fee_cents' => 150000,
        ]);

        $this->assertSame('pending', $result['participation_status']);
        $this->assertTrue($result['invitation_sent']);
        Mail::assertSent(ArtistEventInvitationMail::class, fn ($mail) => $mail->hasTo($artistUser->email));

        $invitation = DB::table('artist_invitations')->where('id', $result['invitation_id'])->first();
        $artistId = (int) $result['artist']->id;

        $this->assertSame('pending', $invitation->status);
        $this->assertDatabaseHas('event_artist', [
            'app_id' => $app->id,
            'event_id' => $event->id,
            'artist_id' => $artistId,
            'status' => 'pending',
        ]);

        $response = $workflow->respondByToken(
            $app->id,
            $invitation->token,
            $artistUser,
            'accept',
            null,
            '127.0.0.1',
            'phpunit'
        );

        $this->assertSame('confirmed', $response['status']);
        $this->assertDatabaseHas('artist_invitations', ['id' => $invitation->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $event->id,
            'artist_id' => $artistId,
            'status' => 'confirmed',
            'response_user_id' => $artistUser->id,
        ]);
    }

    public function test_rejection_is_a_distinct_audited_state_with_optional_reason(): void
    {
        Mail::fake();
        [$app, $producer, $artistUser, $event] = $this->fixtures();
        $workflow = app(ArtistInvitationWorkflowService::class);

        $result = $workflow->inviteRegistered($app->id, $event, $producer, $artistUser, [
            'participation_type' => 'DJ set',
        ]);
        $invitation = DB::table('artist_invitations')->where('id', $result['invitation_id'])->first();

        $response = $workflow->respondByToken(
            $app->id,
            $invitation->token,
            $artistUser,
            'reject',
            'Conflito de agenda',
            '127.0.0.1',
            'phpunit'
        );

        $this->assertSame('declined', $response['status']);
        $this->assertDatabaseHas('artist_invitations', [
            'id' => $invitation->id,
            'status' => 'declined',
            'decline_reason' => 'Conflito de agenda',
        ]);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $event->id,
            'artist_id' => $result['artist']->id,
            'status' => 'declined',
            'decline_reason' => 'Conflito de agenda',
        ]);
        $this->assertDatabaseHas('artist_invitation_events', [
            'invitation_id' => $invitation->id,
            'event_type' => 'invite_rejected',
        ]);
    }

    public function test_external_invite_reuses_record_and_claim_does_not_auto_accept(): void
    {
        Mail::fake();
        [$app, $producer, , $event] = $this->fixtures();
        $workflow = app(ArtistInvitationWorkflowService::class);

        $first = $workflow->inviteExternal($app->id, $event, $producer, 'new.artist@example.test', [
            'participation_type' => 'participação especial',
        ]);
        $second = $workflow->inviteExternal($app->id, $event, $producer, 'new.artist@example.test', [
            'participation_type' => 'participação especial',
        ]);

        $this->assertSame($first['invitation_id'], $second['invitation_id']);
        $this->assertSame(1, DB::table('artist_invitations')->where('event_id', $event->id)->where('identifier_hash', hash('sha256', 'new.artist@example.test'))->count());

        $newUser = $this->user('Artista Externo', 'new.artist@example.test');
        $claim = $workflow->claimPending($app->id, $newUser);

        $this->assertSame(1, $claim['claimed']);
        $invitation = DB::table('artist_invitations')->where('id', $first['invitation_id'])->first();
        $this->assertSame('pending', $invitation->status);
        $this->assertSame($newUser->id, (int) $invitation->invited_user_id);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $event->id,
            'artist_id' => $invitation->artist_id,
            'status' => 'pending',
        ]);
    }

    public function test_non_material_update_keeps_acceptance_but_material_change_requires_new_acceptance(): void
    {
        Mail::fake();
        [$app, $producer, $artistUser, $event] = $this->fixtures();
        $workflow = app(ArtistInvitationWorkflowService::class);

        $result = $workflow->inviteRegistered($app->id, $event, $producer, $artistUser, [
            'participation_type' => 'show',
            'description' => 'Primeira descrição',
            'stage' => 'Palco A',
        ]);
        $invitation = DB::table('artist_invitations')->where('id', $result['invitation_id'])->first();

        $workflow->respondByToken($app->id, $invitation->token, $artistUser, 'accept', null, '127.0.0.1', 'phpunit');

        $nonMaterial = $workflow->updateParticipation($app->id, $event->id, $result['artist']->id, $producer, [
            'description' => 'Descrição revisada',
        ]);
        $this->assertFalse($nonMaterial['requires_reacceptance']);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $event->id,
            'artist_id' => $result['artist']->id,
            'status' => 'confirmed',
            'description' => 'Descrição revisada',
        ]);

        $material = $workflow->updateParticipation($app->id, $event->id, $result['artist']->id, $producer, [
            'stage' => 'Palco B',
        ]);
        $this->assertTrue($material['requires_reacceptance']);
        $this->assertContains('stage', $material['material_changes']);
        $this->assertDatabaseHas('event_artist', [
            'event_id' => $event->id,
            'artist_id' => $result['artist']->id,
            'status' => 'pending_change',
            'stage' => 'Palco B',
        ]);
        $this->assertDatabaseHas('artist_invitations', [
            'id' => $result['invitation_id'],
            'status' => 'pending_change',
        ]);
    }

    public function test_unverified_user_can_receive_invite_but_cannot_answer_until_verified(): void
    {
        Mail::fake();
        [$app, $producer, , $event] = $this->fixtures();

        $artistUser = User::create([
            'first_name' => 'Artista sem verificação',
            'email' => 'unverified.artist@example.test',
            'user_name' => 'unverified-artist',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => null,
        ]);

        $workflow = app(ArtistInvitationWorkflowService::class);
        $result = $workflow->inviteRegistered($app->id, $event, $producer, $artistUser, [
            'participation_type' => 'show',
        ]);
        $invitation = DB::table('artist_invitations')->where('id', $result['invitation_id'])->first();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $workflow->respondByToken($app->id, $invitation->token, $artistUser, 'accept', null, '127.0.0.1', 'phpunit');
    }

    private function fixtures(): array
    {
        $app = $this->applicationFixture('cutinapp');
        $producer = $this->user('Produtor Convite', 'producer-invite@example.test');
        $artistUser = $this->user('Artista Convite', 'artist-invite@example.test');

        $production = Production::query()->create([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'user_id' => $producer->id,
            'name' => 'Produção Convites',
            'slug' => 'producao-convites',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $eventId = DB::table('events')->insertGetId([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'production_id' => $production->id,
            'title' => 'Evento Convite',
            'slug' => 'evento-convite',
            'description' => 'Evento para testar convites artísticos.',
            'category' => 'Música',
            'address' => 'Rua Teste',
            'city' => 'Goiânia',
            'uf' => 'GO',
            'start_date' => now()->addDays(5),
            'end_date' => now()->addDays(5)->addHours(4),
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'is_private' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$app, $producer, $artistUser, Event::query()->with('production')->findOrFail($eventId)];
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)).'-'.substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user), 'X-Peter-App' => 'cutinapp'];
    }
}
