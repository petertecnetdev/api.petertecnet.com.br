<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Party;
use App\Models\Permission;
use App\Models\ResourceRef;
use App\Models\ResourceRelationship;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ContextualAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class ContextualAccessHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_malformed_app_less_establishment_assignment_is_rejected(): void
    {
        [$user, , , $establishment] = $this->foundation();
        $role = Role::create(['code' => 'manager', 'name' => 'Gerente']);

        $this->expectException(LogicException::class);
        RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'application_id' => null,
            'establishment_id' => $establishment,
            'context_key' => 'invalid',
        ]);
    }

    public function test_legacy_malformed_app_less_establishment_assignment_does_not_become_global(): void
    {
        [$user, $appA, $appB, $establishment] = $this->foundation();
        $permission = Permission::create(['code' => 'members.manage', 'name' => 'Administrar membros']);
        $role = Role::create(['code' => 'manager', 'name' => 'Gerente']);
        $role->permissions()->attach($permission);

        DB::table('role_assignments')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'application_id' => null,
            'establishment_id' => $establishment,
            'context_key' => 'legacy-malformed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ContextualAccessService::class);
        $this->assertFalse($service->hasPermission($user, 'members.manage', $appA->id, $establishment));
        $this->assertFalse($service->hasPermission($user, 'members.manage', $appB->id, $establishment));
    }

    public function test_resource_registry_namespaces_equal_ids_and_relationship_grants_only_that_resource(): void
    {
        [$user, $appA, $appB] = $this->foundation();

        $resourceA = ResourceRef::create([
            'application_id' => $appA->id,
            'resource_type' => 'agreement',
            'resource_id' => 501,
            'label' => 'Contrato A',
        ]);
        $resourceB = ResourceRef::create([
            'application_id' => $appB->id,
            'resource_type' => 'agreement',
            'resource_id' => 501,
            'label' => 'Contrato B',
        ]);

        ResourceRelationship::create([
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'application_id' => $appA->id,
            'resource_ref_id' => $resourceA->id,
            'relationship_type' => 'tenant',
            'resource_type' => 'agreement',
            'resource_id' => 501,
            'relationship_key' => ResourceRelationship::relationshipKey('user', $user->id, 'tenant', 'agreement', 501, $appA->id, $resourceA->uuid),
        ]);

        $service = app(ContextualAccessService::class);
        $this->assertTrue($service->canForResource($user, 'agreements.sign', $resourceA));
        $this->assertFalse($service->canForResource($user, 'agreements.sign', $resourceB));
        $this->assertTrue($service->hasRelationship($user, 'tenant', 'agreement', 501, $appA->id, $resourceA->uuid));
        $this->assertFalse($service->hasRelationship($user, 'tenant', 'agreement', 501, $appB->id, $resourceB->uuid));
    }

    public function test_party_representative_inherits_party_resource_relationship_without_becoming_the_party(): void
    {
        [$user, $appA] = $this->foundation();
        $party = Party::create([
            'type' => 'organization',
            'legal_name' => 'Empresa Representada',
            'document_type' => 'cnpj',
            'document' => '12.345.678/0001-90',
        ]);
        $party->users()->attach($user->id, ['relationship_type' => 'representative', 'status' => 'active']);

        $resource = ResourceRef::create([
            'application_id' => $appA->id,
            'resource_type' => 'agreement',
            'resource_id' => 77,
        ]);
        ResourceRelationship::create([
            'subject_type' => 'party',
            'subject_id' => $party->id,
            'application_id' => $appA->id,
            'resource_ref_id' => $resource->id,
            'relationship_type' => 'landlord',
            'resource_type' => 'agreement',
            'resource_id' => 77,
            'relationship_key' => ResourceRelationship::relationshipKey('party', $party->id, 'landlord', 'agreement', 77, $appA->id, $resource->uuid),
        ]);

        $party->refresh();
        $this->assertNull($party->getRawOriginal('document'));
        $this->assertNotNull($party->getRawOriginal('document_encrypted'));
        $this->assertNotNull($party->getRawOriginal('document_hash'));
        $this->assertSame('12.345.678/0001-90', $party->document);
        $this->assertTrue(app(ContextualAccessService::class)->hasRelationship($user, 'landlord', 'agreement', 77, $appA->id, $resource->uuid));
    }

    public function test_snapshot_relationships_are_bounded_and_report_truncation(): void
    {
        [$user, $appA] = $this->foundation();
        config()->set('contextual_access.snapshot_relationship_limit', 2);

        foreach ([1, 2, 3] as $id) {
            $resource = ResourceRef::create([
                'application_id' => $appA->id,
                'resource_type' => 'agreement',
                'resource_id' => $id,
            ]);
            ResourceRelationship::create([
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'application_id' => $appA->id,
                'resource_ref_id' => $resource->id,
                'relationship_type' => 'tenant',
                'resource_type' => 'agreement',
                'resource_id' => $id,
                'relationship_key' => ResourceRelationship::relationshipKey('user', $user->id, 'tenant', 'agreement', $id, $appA->id, $resource->uuid),
            ]);
        }

        $snapshot = app(ContextualAccessService::class)->snapshot($user, $appA->id);
        $this->assertCount(2, $snapshot['relationships']);
        $this->assertSame(3, $snapshot['relationship_summary']['total']);
        $this->assertTrue($snapshot['relationship_summary']['truncated']);
    }

    private function foundation(): array
    {
        $user = User::create([
            'first_name' => 'Context',
            'email' => uniqid('context-', true) . '@example.test',
            'user_name' => uniqid('context-', true),
            'password' => Hash::make('Test1234!'),
        ]);
        $appA = Application::create(['name' => uniqid('App A '), 'slug' => uniqid('app-a-'), 'is_active' => true]);
        $appB = Application::create(['name' => uniqid('App B '), 'slug' => uniqid('app-b-'), 'is_active' => true]);
        $establishment = DB::table('establishments')->insertGetId([
            'app_id' => $appA->id,
            'name' => 'Estabelecimento Contextual',
            'slug' => uniqid('est-'),
            'user_id' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('application_establishment')->insert([
            'application_id' => $appB->id,
            'establishment_id' => $establishment,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $appA, $appB, $establishment];
    }
}
