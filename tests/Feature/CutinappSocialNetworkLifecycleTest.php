<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappSocialNetworkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_feed_notification_profile_and_report_moderation_work_together(): void
    {
        $producer = $this->user('Produtor Rede', 'producer-network@cutinapp.test');
        $participant = $this->user('Participante Rede', 'participant-network@cutinapp.test');
        $admin = $this->user('Admin Rede', 'admin-network@cutinapp.test');
        $adminProfile = Profile::create(['name' => 'Administrador', 'permissions' => []]);
        $admin->update(['profile_id' => $adminProfile->id]);

        $ph = $this->headersFor($producer);
        $uh = $this->headersFor($participant);
        $ah = $this->headersFor($admin);

        $production = $this->withHeaders($ph)->postJson('/api/cutinapp/productions', [
            'name' => 'Rede Produções', 'city' => 'São Paulo', 'uf' => 'SP',
        ])->assertCreated()->json('production');

        $event = $this->withHeaders($ph)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'],
            'title' => 'Festival Rede Social',
            'description' => 'Evento para validar recursos sociais integrados.',
            'address' => 'Rua Social, 10',
            'city' => 'São Paulo', 'uf' => 'SP',
            'start_date' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDays(3)->addHours(4)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $ticket = $this->withHeaders($ph)->postJson('/api/cutinapp/courtesies', [
            'event_id' => $event['id'], 'name' => 'Entrada Rede', 'quantity' => 20,
        ])->assertCreated()->json('ticket');
        $this->withHeaders($ph)->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();

        $this->withHeaders($uh)->postJson('/api/cutinapp/follow', [
            'target_type' => 'production', 'target_id' => $production['id'],
        ])->assertOk();
        $this->withHeaders($uh)->putJson('/api/cutinapp/events/' . $event['id'] . '/engagement', [
            'is_interested' => true, 'is_favorite' => true,
        ])->assertOk();

        $postId = $this->withHeaders($uh)->postJson('/api/cutinapp/events/' . $event['id'] . '/community', [
            'body' => 'Alguém mais animado para este festival?',
        ])->assertCreated()->json('post_id');
        $this->assertNotNull($postId);

        $this->withHeaders($uh)->postJson('/api/cutinapp/events/' . $event['id'] . '/report', [
            'reason' => 'misleading', 'details' => 'Quero que a moderação confira uma informação do evento.',
        ])->assertOk();

        $reportId = $this->withHeaders($ah)->getJson('/api/cutinapp/moderation/reports?status=open')
            ->assertOk()
            ->assertJsonPath('counts.open', 1)
            ->json('reports.data.0.id');

        $this->withHeaders($ah)->putJson('/api/cutinapp/moderation/reports/' . $reportId, [
            'status' => 'resolved', 'moderation_note' => 'Informação verificada pela equipe.',
        ])->assertOk()->assertJsonPath('report.status', 'resolved');

        $pass = $this->withHeaders($uh)->postJson('/api/cutinapp/passes/claim/' . $ticket['id'])
            ->assertCreated()->json('pass');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $participant->id,
            'type' => 'ticket_issued',
            'reference_id' => $pass['id'],
        ]);

        $inbox = $this->withHeaders($uh)->getJson('/api/cutinapp/notifications')
            ->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $inbox->json('unread_count'));
        $notificationId = AppNotification::query()->where('user_id', $participant->id)->where('type', 'ticket_issued')->value('id');
        $this->withHeaders($uh)->postJson('/api/cutinapp/notifications/' . $notificationId . '/read')->assertOk();
        $this->withHeaders($uh)->postJson('/api/cutinapp/notifications/read-all')->assertOk();
        $this->withHeaders($uh)->getJson('/api/cutinapp/notifications')->assertOk()->assertJsonPath('unread_count', 0);

        $feed = $this->withHeaders($uh)->getJson('/api/cutinapp/feed')->assertOk();
        $this->assertSame($event['id'], $feed->json('feed.data.0.id'));
        $this->assertSame($postId, $feed->json('community_activity.0.id'));

        $this->withHeaders($uh)->getJson('/api/cutinapp/profile/overview')
            ->assertOk()
            ->assertJsonPath('stats.upcoming_with_ticket', 1)
            ->assertJsonPath('stats.interested', 1)
            ->assertJsonPath('stats.favorites', 1)
            ->assertJsonPath('stats.posts', 1);
    }

    public function test_non_admin_cannot_access_report_moderation(): void
    {
        $user = $this->user('Participante', 'no-moderation@cutinapp.test');
        $this->withHeaders($this->headersFor($user))
            ->getJson('/api/cutinapp/moderation/reports')
            ->assertForbidden();
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user), 'X-Peter-App' => 'cutinapp'];
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)) . '-' . substr(md5($email), 0, 6),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
