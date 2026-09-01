<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventCommunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_post_reply_rate_report_and_see_social_profile(): void
    {
        $producer = $this->user('Produtor Comunidade', 'community-producer@cutinapp.test');
        $participant = $this->user('Participante Comunidade', 'community-user@cutinapp.test');
        $ph = $this->headersFor($producer);
        $uh = $this->headersFor($participant);
        $app = Application::where('slug', 'cutinapp')->firstOrFail();

        $production = $this->withHeaders($ph)->postJson('/api/cutinapp/productions', [
            'name' => 'Comunidade Produções', 'city' => 'São Paulo', 'uf' => 'SP',
        ])->assertCreated()->json('production');

        $event = $this->withHeaders($ph)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'], 'title' => 'Festival Comunidade',
            'description' => 'Evento para testar a comunidade.', 'address' => 'Rua Comunidade, 10',
            'city' => 'São Paulo', 'uf' => 'SP',
            'start_date' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDays(3)->addHours(4)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');

        $ticket = $this->withHeaders($ph)->postJson('/api/cutinapp/courtesies', [
            'event_id' => $event['id'], 'name' => 'Entrada Comunidade', 'quantity' => 20,
        ])->assertCreated()->json('ticket');
        $this->withHeaders($ph)->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();

        $this->assertFalse((bool) DB::table('events')->where('id', $event['id'])->value('is_private'));

        $postId = $this->withHeaders($uh)->postJson('/api/cutinapp/events/' . $event['id'] . '/community', [
            'body' => 'Quem mais vai para este evento?',
        ])->assertCreated()->json('post_id');

        $this->withHeaders($ph)->postJson('/api/cutinapp/events/' . $event['id'] . '/community', [
            'body' => 'A produção estará por aqui para tirar dúvidas.', 'parent_id' => $postId,
        ])->assertCreated();

        $this->withHeaders($uh)->postJson('/api/cutinapp/community/' . $postId . '/like')->assertOk()->assertJsonPath('liked', true);
        $this->withHeaders($uh)->putJson('/api/cutinapp/events/' . $event['id'] . '/rating', ['rating' => 5])->assertOk()->assertJsonPath('rating', 5);
        $this->withHeaders($uh)->postJson('/api/cutinapp/events/' . $event['id'] . '/report', ['reason' => 'misleading', 'details' => 'Informação a ser conferida pela moderação.'])->assertOk();
        $this->withHeaders($uh)->postJson('/api/cutinapp/events/' . $event['id'] . '/report', ['reason' => 'harassment', 'details' => 'Atualização da denúncia.'])->assertOk();

        $this->assertDatabaseCount('cutinapp_event_reports', 1);
        $this->assertDatabaseHas('cutinapp_event_reports', ['app_id'=>$app->id,'event_id'=>$event['id'],'user_id'=>$participant->id,'status'=>'open','reason'=>'harassment']);

        $this->getJson('/api/cutinapp/events/public/' . $event['slug'] . '/community')
            ->assertOk()
            ->assertJsonPath('rating.average', 5)
            ->assertJsonPath('rating.total', 1)
            ->assertJsonPath('posts.data.0.body', 'Quem mais vai para este evento?')
            ->assertJsonPath('posts.data.0.comments_count', 1)
            ->assertJsonCount(1, 'posts.data.0.replies');

        $this->withHeaders($uh)->putJson('/api/cutinapp/events/' . $event['id'] . '/engagement', ['is_interested'=>true,'is_favorite'=>true])->assertOk();
        $this->withHeaders($uh)->postJson('/api/cutinapp/passes/claim/' . $ticket['id'])->assertCreated();

        $this->withHeaders($uh)->getJson('/api/cutinapp/profile/overview')
            ->assertOk()
            ->assertJsonPath('stats.upcoming_with_ticket', 1)
            ->assertJsonPath('stats.interested', 1)
            ->assertJsonPath('stats.favorites', 1)
            ->assertJsonPath('stats.posts', 1)
            ->assertJsonPath('upcoming_with_ticket.0.id', $event['id']);
    }

    public function test_user_cannot_delete_another_users_post_but_production_owner_can_moderate(): void
    {
        $producer = $this->user('Produtor Moderador', 'moderator-producer@cutinapp.test');
        $author = $this->user('Autor Post', 'post-author@cutinapp.test');
        $other = $this->user('Outro Usuario', 'other-user@cutinapp.test');
        $ph = $this->headersFor($producer);
        $ah = $this->headersFor($author);
        $oh = $this->headersFor($other);

        $production = $this->withHeaders($ph)->postJson('/api/cutinapp/productions', [
            'name' => 'Produção Moderadora', 'city' => 'Rio de Janeiro', 'uf' => 'RJ',
        ])->assertCreated()->json('production');
        $event = $this->withHeaders($ph)->postJson('/api/cutinapp/events', [
            'production_id' => $production['id'], 'title' => 'Evento Moderado',
            'description' => 'Evento para testar moderação da comunidade.', 'address' => 'Rua Moderação, 20',
            'city' => 'Rio de Janeiro', 'uf' => 'RJ',
            'start_date' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'end_date' => now()->addDays(2)->addHours(4)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('event');
        $this->withHeaders($ph)->postJson('/api/cutinapp/courtesies', [
            'event_id' => $event['id'], 'name' => 'Entrada Moderada', 'quantity' => 10,
        ])->assertCreated();
        $this->withHeaders($ph)->postJson('/api/cutinapp/events/' . $event['id'] . '/publish')->assertOk();

        $postId = $this->withHeaders($ah)->postJson('/api/cutinapp/events/' . $event['id'] . '/community', [
            'body' => 'Mensagem da comunidade.',
        ])->assertCreated()->json('post_id');

        $this->withHeaders($oh)->deleteJson('/api/cutinapp/community/' . $postId)->assertForbidden();
        $this->assertDatabaseHas('cutinapp_event_posts', ['id'=>$postId,'status'=>'published']);

        $this->withHeaders($ph)->deleteJson('/api/cutinapp/community/' . $postId)->assertOk();
        $this->assertDatabaseHas('cutinapp_event_posts', ['id'=>$postId,'status'=>'hidden']);
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
