<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RasoioApplicationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rasoio_availability_rejects_employer_from_another_application(): void
    {
        [$owner, $otherOwner, $otherEstablishment] = $this->applicationFixtures();

        $employer = Employer::create([
            'user_id' => $otherOwner->id,
            'establishment_id' => $otherEstablishment->id,
            'role' => 'barber',
            'permissions' => [],
            'created_by' => $otherOwner->id,
            'updated_by' => $otherOwner->id,
        ]);

        $token = auth('api')->login($owner);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/rasoio/availability/times', [
                'employer_id' => $employer->id,
                'date' => now()->addDay()->toDateString(),
                'duration' => 30,
            ])
            ->assertNotFound();
    }

    public function test_rasoio_employer_endpoint_rejects_other_application(): void
    {
        Mail::fake();
        [$owner, $otherOwner, $otherEstablishment] = $this->applicationFixtures();

        $token = auth('api')->login($otherOwner);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/rasoio/employers', [
                'user_id' => $owner->id,
                'establishment_id' => $otherEstablishment->id,
                'app_id' => 2,
                'role' => 'barber',
                'permissions' => [],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('employers', 0);
    }

    private function applicationFixtures(): array
    {
        $rasoio = Application::create([
            'name' => 'Rasoio',
            'slug' => 'rasoio',
            'is_active' => true,
        ]);

        $otherApp = Application::create([
            'name' => 'Outra aplicação',
            'slug' => 'outra-app',
            'is_active' => true,
        ]);

        $this->assertSame(1, (int) $rasoio->id);
        $this->assertSame(2, (int) $otherApp->id);

        $owner = $this->user('rasoio-owner@example.test', 'rasoio-owner');
        $otherOwner = $this->user('other-owner@example.test', 'other-owner');

        Establishment::create([
            'app_id' => $rasoio->id,
            'name' => 'Barbearia Rasoio',
            'slug' => 'barbearia-rasoio',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $otherEstablishment = Establishment::create([
            'app_id' => $otherApp->id,
            'name' => 'Empresa de outro app',
            'slug' => 'empresa-outro-app',
            'user_id' => $otherOwner->id,
            'created_by' => $otherOwner->id,
            'updated_by' => $otherOwner->id,
        ]);

        return [$owner, $otherOwner, $otherEstablishment];
    }

    private function user(string $email, string $username): User
    {
        return User::create([
            'first_name' => 'Teste',
            'email' => $email,
            'user_name' => $username,
            'password' => Hash::make('Test1234!'),
        ]);
    }
}
