<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Permission;
use App\Models\ResourceRelationship;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ContextualAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContextualAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_can_hold_different_roles_and_domain_relationships_without_leakage(): void
    {
        $user = User::create([
            'first_name' => 'Joao',
            'email' => 'joao.context@example.test',
            'user_name' => 'joao-context',
            'password' => Hash::make('Test1234!'),
        ]);

        $rasoio = Application::create(['name' => 'Rasoio', 'slug' => 'rasoio', 'is_active' => true]);
        $nexus = Application::create(['name' => 'Nexus', 'slug' => 'nexus', 'is_active' => true]);
        $locaio = Application::create(['name' => 'Locaio', 'slug' => 'locaio', 'is_active' => true]);

        $rasoioEstablishment = $this->establishment($user, $rasoio, 'Barbearia Central', 'barbearia-central');
        $nexusEstablishment = $this->establishment($user, $nexus, 'Loja Central', 'loja-central');

        $manageAppointments = Permission::create(['code' => 'appointments.manage', 'name' => 'Administrar agendamentos']);
        $viewOrders = Permission::create(['code' => 'orders.view', 'name' => 'Visualizar pedidos']);

        $manager = Role::create(['code' => 'manager', 'name' => 'Gerente']);
        $manager->permissions()->attach($manageAppointments);
        $employee = Role::create(['code' => 'employee', 'name' => 'Colaborador']);
        $employee->permissions()->attach($viewOrders);

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $manager->id,
            'application_id' => $rasoio->id,
            'establishment_id' => $rasoioEstablishment->id,
            'context_key' => RoleAssignment::contextKey($rasoio->id, $rasoioEstablishment->id),
        ]);

        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $employee->id,
            'application_id' => $nexus->id,
            'establishment_id' => $nexusEstablishment->id,
            'context_key' => RoleAssignment::contextKey($nexus->id, $nexusEstablishment->id),
        ]);

        ResourceRelationship::create([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'application_id' => $locaio->id,
            'relationship_type' => 'tenant',
            'resource_type' => 'agreement',
            'resource_id' => 501,
            'relationship_key' => ResourceRelationship::relationshipKey('user', $user->id, 'tenant', 'agreement', 501, $locaio->id),
        ]);

        ResourceRelationship::create([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'application_id' => $locaio->id,
            'relationship_type' => 'landlord',
            'resource_type' => 'agreement',
            'resource_id' => 800,
            'relationship_key' => ResourceRelationship::relationshipKey('user', $user->id, 'landlord', 'agreement', 800, $locaio->id),
        ]);

        $service = app(ContextualAccessService::class);

        $this->assertTrue($service->hasPermission(
            $user,
            'appointments.manage',
            $rasoio->id,
            $rasoioEstablishment->id,
        ));
        $this->assertFalse($service->hasPermission(
            $user,
            'appointments.manage',
            $nexus->id,
            $nexusEstablishment->id,
        ));
        $this->assertTrue($service->hasPermission(
            $user,
            'orders.view',
            $nexus->id,
            $nexusEstablishment->id,
        ));

        $this->assertTrue($service->hasRelationship($user, 'tenant', 'agreement', 501, $locaio->id));
        $this->assertFalse($service->hasRelationship($user, 'tenant', 'agreement', 800, $locaio->id));
        $this->assertTrue($service->hasRelationship($user, 'landlord', 'agreement', 800, $locaio->id));

        $locaioSnapshot = $service->snapshot($user, $locaio->id);
        $this->assertCount(2, $locaioSnapshot['relationships']);
        $this->assertCount(0, $locaioSnapshot['roles']);
    }

    private function establishment(User $user, Application $application, string $name, string $slug): Establishment
    {
        $id = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => $name,
            'slug' => $slug,
            'user_id' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Establishment::findOrFail($id);
    }
}
