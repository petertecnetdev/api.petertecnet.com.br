<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\RelationshipType;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContextualAccessSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'ecosystem.manage' => 'Administrar o ecossistema',
            'application.manage' => 'Administrar aplicação',
            'establishment.view' => 'Visualizar estabelecimento',
            'establishment.manage' => 'Administrar estabelecimento',
            'members.view' => 'Visualizar membros',
            'members.manage' => 'Administrar membros e vínculos',
            'catalog.view' => 'Visualizar catálogo',
            'catalog.manage' => 'Administrar catálogo',
            'appointments.view' => 'Visualizar agendamentos',
            'appointments.manage' => 'Administrar agendamentos',
            'orders.view' => 'Visualizar pedidos',
            'orders.manage' => 'Administrar pedidos',
            'payments.view' => 'Visualizar pagamentos',
            'payments.manage' => 'Administrar pagamentos',
            'reports.view' => 'Visualizar relatórios',
            'settings.manage' => 'Administrar configurações',
            'resources.view' => 'Visualizar recursos relacionados',
            'resources.manage' => 'Administrar recursos relacionados',
            'agreements.view' => 'Visualizar acordos e contratos',
            'agreements.manage' => 'Administrar acordos e contratos',
            'agreements.sign' => 'Assinar acordos e contratos',
        ];

        $roles = [
            'super_admin' => ['name' => 'Superadministrador', 'permissions' => array_keys($permissions)],
            'administrator' => ['name' => 'Administrador', 'permissions' => array_values(array_diff(array_keys($permissions), ['ecosystem.manage']))],
            'owner' => [
                'name' => 'Proprietário',
                'permissions' => [
                    'establishment.view', 'establishment.manage', 'members.view', 'members.manage', 'catalog.view',
                    'catalog.manage', 'appointments.view', 'appointments.manage', 'orders.view', 'orders.manage',
                    'payments.view', 'payments.manage', 'reports.view', 'settings.manage', 'resources.view', 'resources.manage',
                    'agreements.view', 'agreements.manage', 'agreements.sign',
                ],
            ],
            'manager' => [
                'name' => 'Gerente',
                'permissions' => [
                    'establishment.view', 'members.view', 'members.manage', 'catalog.view', 'catalog.manage',
                    'appointments.view', 'appointments.manage', 'orders.view', 'orders.manage', 'payments.view',
                    'reports.view', 'resources.view', 'resources.manage', 'agreements.view', 'agreements.manage',
                ],
            ],
            'employee' => ['name' => 'Colaborador', 'permissions' => ['establishment.view', 'catalog.view', 'appointments.view', 'orders.view', 'resources.view']],
            'operator' => ['name' => 'Operador', 'permissions' => ['establishment.view', 'catalog.view', 'appointments.view', 'appointments.manage', 'orders.view', 'orders.manage', 'resources.view']],
            'financial_manager' => ['name' => 'Gestor financeiro', 'permissions' => ['establishment.view', 'orders.view', 'payments.view', 'payments.manage', 'reports.view']],
            'viewer' => ['name' => 'Visualizador', 'permissions' => ['establishment.view', 'catalog.view', 'appointments.view', 'orders.view', 'reports.view', 'resources.view', 'agreements.view']],
        ];

        $relationshipTypes = [
            'tenant' => ['Locatário', ['lessee', 'inquilino', 'inquilina']],
            'landlord' => ['Locador', ['lessor', 'proprietario_locador', 'proprietaria_locadora']],
            'guarantor' => ['Fiador', ['fiadora', 'guarantee_provider']],
            'representative' => ['Representante', ['procurador', 'procuradora', 'manager']],
            'customer' => ['Cliente', ['client']],
            'supplier' => ['Fornecedor', ['vendor']],
            'beneficiary' => ['Beneficiário', ['beneficiary_party']],
            'contractor' => ['Contratado', ['service_provider']],
        ];

        DB::transaction(function () use ($permissions, $roles, $relationshipTypes) {
            foreach ($permissions as $code => $name) {
                Permission::query()->updateOrCreate(['code' => $code], ['name' => $name]);
            }

            foreach ($roles as $code => $definition) {
                $role = Role::query()->updateOrCreate(['code' => $code], ['name' => $definition['name'], 'is_system' => true]);
                $role->permissions()->sync(Permission::query()->whereIn('code', $definition['permissions'])->pluck('id'));
            }

            foreach ($relationshipTypes as $code => [$name, $aliases]) {
                RelationshipType::query()->updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'category' => 'business', 'aliases' => $aliases, 'is_active' => true],
                );
            }
        });
    }
}
