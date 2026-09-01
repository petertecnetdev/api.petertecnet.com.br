<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappCheckinScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_checkin_permission_does_not_grant_access_to_unscoped_event(): void
    {
        $application = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Owner', 'scope-owner@cutinapp.test');
        $operator = $this->user('Operator', 'scope-operator@cutinapp.test');
        $operatorProfile = Profile::create([
            'name' => 'Cutinapp Checkin Operator',
            'permissions' => ['event_checkin', 'ticket_checkin'],
        ]);
        $operator->update(['profile_id' => $operatorProfile->id]);

        $production = $this->withHeaders($this->headersFor($owner))
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Escopo',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production');

        $event = $this->withHeaders($this->headersFor($owner))
            ->postJson('/api/cutinapp/events', [
                'production_id' => $production['id'],
                'title' => 'Evento Escopo',
                'description' => 'Evento usado para validar autorização de portaria por escopo.',
                'address' => 'Rua Escopo, 10',
                'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertCreated()
            ->json('event');

        DB::table('application_user')->updateOrInsert(
            ['application_id' => $application->id, 'user_id' => $operator->id],
            [
                'role' => 'staff',
                'status' => 'active',
                'metadata' => null,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->withHeaders($this->headersFor($operator))
            ->getJson('/api/cutinapp/checkin/event/' . $event['id'] . '/stats')
            ->assertForbidden();

        DB::table('application_user')
            ->where('application_id', $application->id)
            ->where('user_id', $operator->id)
            ->update([
                'metadata' => json_encode(['event_ids' => [$event['id']]], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        $this->withHeaders($this->headersFor($operator))
            ->getJson('/api/cutinapp/checkin/event/' . $event['id'] . '/stats')
            ->assertOk()
            ->assertJsonPath('event.id', $event['id']);
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
            'user_name' => strtolower($name) . '-' . substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }
}
