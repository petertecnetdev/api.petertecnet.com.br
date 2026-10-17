<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\Application;
use App\Models\EventPass;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappAdministratorCheckinTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_and_cutinapp_delegated_admin_can_validate_tickets_for_other_producers(): void
    {
        $root = $this->user('Root', ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $admin = $this->user('Admin Cutinapp', 'cutinapp@gmail.com');
        $producer = $this->user('Produtor', 'checkin-producer@cutinapp.test');
        $participant = $this->user('Participante', 'checkin-participant@cutinapp.test');
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();

        $production = $this->production($producer);
        [$firstEvent, $firstTicket] = $this->publishedEvent($producer, $production['id'], 'Evento de outro produtor A');
        [$secondEvent, $secondTicket] = $this->publishedEvent($producer, $production['id'], 'Evento de outro produtor B');

        $passA = $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$firstTicket['id'])
            ->assertCreated()->json('pass');
        $passB = $this->withHeaders($this->headersFor($participant))
            ->postJson('/api/cutinapp/passes/claim/'.$secondTicket['id'])
            ->assertCreated()->json('pass');

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/v1/apps/cutinapp/checkin/events')
            ->assertOk()->assertJsonCount(0, 'events.data');

        $service = app(ApplicationAdminService::class);
        $profile = $service->createProfile($app->id, $root, [
            'name' => 'Portaria Cutinapp',
            'permissions' => ['checkin.view', 'checkin.manage'],
        ]);
        $service->assign($app->id, $root, $admin->email, $profile->id);

        $this->withHeaders($this->headersFor($root))
            ->getJson('/api/v1/apps/cutinapp/checkin/events?q=outro%20produtor')
            ->assertOk()->assertJsonCount(2, 'events.data');

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/v1/apps/cutinapp/checkin/events?q=outro%20produtor')
            ->assertOk()->assertJsonCount(2, 'events.data');

        $this->travelTo(Carbon::parse($firstEvent['start_date'])->addMinute());

        $this->withHeaders($this->headersFor($root))
            ->postJson('/api/cutinapp/checkin', ['event_id' => $firstEvent['id'], 'token' => $passA['token']])
            ->assertOk()->assertJsonPath('message', 'Entrada validada com sucesso.');

        $this->withHeaders($this->headersFor($admin))
            ->postJson('/api/cutinapp/checkin', ['event_id' => $secondEvent['id'], 'token' => $passB['token']])
            ->assertOk()->assertJsonPath('message', 'Entrada validada com sucesso.');

        $this->assertSame($root->id, (int) EventPass::query()->findOrFail($passA['id'])->checked_in_by);
        $this->assertSame($admin->id, (int) EventPass::query()->findOrFail($passB['id'])->checked_in_by);

        $this->withHeaders($this->headersFor($admin))
            ->postJson('/api/cutinapp/checkin', ['event_id' => $secondEvent['id'], 'token' => $passB['token']])
            ->assertStatus(409);

        $this->travelBack();
    }

    public function test_global_legacy_profile_or_grant_for_another_application_never_authorizes_cutinapp(): void
    {
        $root = $this->user('Root 2', ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $producer = $this->user('Produtor 2', 'checkin-producer2@cutinapp.test');
        $outsider = $this->user('Falso admin', 'legacy-admin@cutinapp.test');
        $profile = Profile::create(['name' => 'Administrador', 'permissions' => ['ticket_checkin']]);
        $outsider->update(['profile_id' => $profile->id]);

        $production = $this->production($producer);
        [$event] = $this->publishedEvent($producer, $production['id'], 'Evento protegido');

        $this->withHeaders($this->headersFor($outsider))
            ->getJson('/api/v1/apps/cutinapp/checkin/events')
            ->assertOk()->assertJsonCount(0, 'events.data');

        $this->withHeaders($this->headersFor($outsider))
            ->getJson('/api/cutinapp/checkin/event/'.$event['id'].'/stats')
            ->assertForbidden();

        $other = Application::query()->create(['name' => 'Aplicação alheia', 'slug' => 'checkin-other-app', 'is_active' => true]);
        $service = app(ApplicationAdminService::class);
        $otherProfile = $service->createProfile($other->id, $root, [
            'name' => 'Portaria externa',
            'permissions' => ['checkin.manage', 'checkin.view'],
        ]);
        $service->assign($other->id, $root, $outsider->email, $otherProfile->id);

        $this->withHeaders($this->headersFor($outsider))
            ->getJson('/api/cutinapp/checkin/event/'.$event['id'].'/stats')
            ->assertForbidden();

        $this->withHeaders($this->headersFor($outsider))
            ->getJson('/api/v1/apps/cutinapp/checkin/events')
            ->assertOk()->assertJsonCount(0, 'events.data');
    }

    public function test_revocation_immediately_removes_global_checkin_access(): void
    {
        $root = $this->user('Root 3', ApplicationAdminService::ROOT_ADMIN_EMAIL);
        $producer = $this->user('Produtor 3', 'checkin-producer3@cutinapp.test');
        $admin = $this->user('Admin revogado', 'checkin-revoked@cutinapp.test');
        $production = $this->production($producer);
        [$event] = $this->publishedEvent($producer, $production['id'], 'Evento de revogação');

        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $service = app(ApplicationAdminService::class);
        $profile = $service->createProfile($app->id, $root, [
            'name' => 'Portaria revogável',
            'permissions' => ['checkin.view', 'checkin.manage'],
        ]);
        $grant = $service->assign($app->id, $root, $admin->email, $profile->id);

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/cutinapp/checkin/event/'.$event['id'].'/stats')
            ->assertOk();

        $service->revoke($app->id, $grant->id, $root);

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/cutinapp/checkin/event/'.$event['id'].'/stats')
            ->assertForbidden();

        $this->withHeaders($this->headersFor($admin))
            ->getJson('/api/v1/apps/cutinapp/checkin/events')
            ->assertOk()->assertJsonCount(0, 'events.data');
    }

    private function production(User $user): array
    {
        return $this->withHeaders($this->headersFor($user))
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção externa '.$user->id,
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])->assertCreated()->json('production');
    }

    private function publishedEvent(User $user, int $productionId, string $title): array
    {
        $event = $this->withHeaders($this->headersFor($user))
            ->postJson('/api/cutinapp/events', [
                'production_id' => $productionId,
                'title' => $title,
                'description' => 'Evento real para validar autorização global de portaria.',
                'address' => 'Rua Exemplo, 20',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(3)->format('Y-m-d H:i:s'),
            ])->assertCreated()->json('event');

        $ticket = $this->withHeaders($this->headersFor($user))
            ->postJson('/api/cutinapp/courtesies', [
                'event_id' => $event['id'],
                'name' => 'Cortesia '.$title,
                'quantity' => 10,
            ])->assertCreated()->json('ticket');

        $this->withHeaders($this->headersFor($user))
            ->postJson('/api/cutinapp/events/'.$event['id'].'/publish')->assertOk();

        return [$event, $ticket];
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
            'user_name' => 'checkin-'.substr(md5($email), 0, 12),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
