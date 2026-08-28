<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderApplicationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_rejects_item_from_another_application_before_persistence(): void
    {
        $admin = $this->adminUser();
        $rasoio = $this->application('Rasoio', 'rasoio');
        $nexus = $this->application('Nexus', 'nexus');

        $rasoioEstablishment = $this->establishment($admin, $rasoio, 'Rasoio Shop', 'rasoio-shop');
        $nexusEstablishment = $this->establishment($admin, $nexus, 'Nexus Shop', 'nexus-shop');

        $employerUser = User::create([
            'first_name' => 'Atendente',
            'email' => 'attendant@example.test',
            'user_name' => 'attendant-test',
            'password' => Hash::make('Test1234!'),
        ]);

        $employer = Employer::create([
            'user_id' => $employerUser->id,
            'establishment_id' => $nexusEstablishment->id,
            'created_by' => $admin->id,
        ]);

        $foreignItem = Item::create([
            'app_id' => $rasoio->id,
            'entity_name' => 'establishment',
            'entity_id' => $rasoioEstablishment->id,
            'name' => 'Item Rasoio',
            'type' => 'service',
            'price' => 10,
            'status' => true,
            'user_id' => $admin->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $token = auth('api')->login($admin);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/order', [
                'mode' => 'appointment',
                'app_id' => $nexus->id,
                'entity_name' => 'establishment',
                'entity_id' => $nexusEstablishment->id,
                'attendant_id' => $employer->id,
                'items' => [[
                    'item_id' => $foreignItem->id,
                    'quantity' => 1,
                ]],
                'origin' => 'app',
                'fulfillment' => 'local',
                'payment_status' => 'pending',
                'payment_method' => 'pix',
                'order_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    private function adminUser(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);

        return User::create([
            'first_name' => 'Admin',
            'email' => 'admin-order@example.test',
            'user_name' => 'admin-order',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
    }

    private function application(string $name, string $slug): Application
    {
        return Application::create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function establishment(User $user, Application $application, string $name, string $slug): Establishment
    {
        return Establishment::create([
            'app_id' => $application->id,
            'name' => $name,
            'slug' => $slug,
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
