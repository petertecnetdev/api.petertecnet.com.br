<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Party;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContextualAccessBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::query()->pluck('id', 'code');
        if ($roles->isEmpty()) {
            $this->call(ContextualAccessSeeder::class);
            $roles = Role::query()->pluck('id', 'code');
        }

        $this->backfillGlobalAdministrators($roles);
        $this->backfillEstablishmentOwners($roles);
        $this->backfillEmployments($roles);
        $this->backfillApplicationRoles($roles);
        $this->encryptLegacyPartyDocuments();
    }

    private function backfillGlobalAdministrators($roles): void
    {
        $superAdminId = $roles->get('super_admin');
        if (! $superAdminId) return;

        DB::table('users')
            ->join('profiles', 'profiles.id', '=', 'users.profile_id')
            ->whereIn(DB::raw('LOWER(profiles.name)'), ['administrador', 'administrator', 'admin'])
            ->select('users.id')
            ->orderBy('users.id')
            ->chunkById(200, function ($users) use ($superAdminId) {
                foreach ($users as $user) {
                    $this->assign((int) $user->id, (int) $superAdminId, null, null, [
                        'source' => 'legacy_profile', 'migrated_at' => now()->toIso8601String(),
                    ]);
                }
            }, 'users.id', 'id');
    }

    private function backfillEstablishmentOwners($roles): void
    {
        $ownerRoleId = $roles->get('owner');
        if (! $ownerRoleId) return;

        DB::table('establishments')
            ->whereNotNull('user_id')
            ->select(['id', 'app_id', 'user_id'])
            ->orderBy('id')
            ->chunkById(200, function ($establishments) use ($ownerRoleId) {
                foreach ($establishments as $establishment) {
                    $userId = (int) $establishment->user_id;
                    $establishmentId = (int) $establishment->id;
                    $applicationId = $this->resolveApplicationId($establishmentId, $establishment->app_id ? (int) $establishment->app_id : null);
                    $metadata = ['source' => 'establishment.user_id', 'migrated_at' => now()->toIso8601String()];

                    $this->membership($userId, $establishmentId, $metadata);
                    if ($applicationId) $this->assign($userId, (int) $ownerRoleId, $applicationId, $establishmentId, $metadata);
                }
            });
    }

    private function backfillEmployments($roles): void
    {
        $employeeRoleId = $roles->get('employee');
        if (! $employeeRoleId) return;

        DB::table('employers')
            ->join('establishments', 'establishments.id', '=', 'employers.establishment_id')
            ->whereNotNull('employers.user_id')
            ->select(['employers.id', 'employers.user_id', 'employers.establishment_id', 'employers.role as legacy_role', 'establishments.app_id'])
            ->orderBy('employers.id')
            ->chunkById(200, function ($employments) use ($roles, $employeeRoleId) {
                foreach ($employments as $employment) {
                    $roleCode = $this->mapEmploymentRole($employment->legacy_role);
                    $roleId = $roles->get($roleCode) ?: $employeeRoleId;
                    $userId = (int) $employment->user_id;
                    $establishmentId = (int) $employment->establishment_id;
                    $applicationId = $this->resolveApplicationId($establishmentId, $employment->app_id ? (int) $employment->app_id : null);
                    $metadata = [
                        'source' => 'employers',
                        'legacy_employer_id' => (int) $employment->id,
                        'legacy_role' => $employment->legacy_role,
                        'migrated_at' => now()->toIso8601String(),
                    ];

                    $this->membership($userId, $establishmentId, $metadata);
                    if ($applicationId) $this->assign($userId, (int) $roleId, $applicationId, $establishmentId, $metadata);
                }
            }, 'employers.id', 'id');
    }

    private function backfillApplicationRoles($roles): void
    {
        DB::table('application_user')
            ->where('status', 'active')
            ->whereNotNull('role')
            ->select(['id', 'user_id', 'application_id', 'role'])
            ->orderBy('id')
            ->chunkById(200, function ($memberships) use ($roles) {
                foreach ($memberships as $membership) {
                    $roleCode = $this->mapApplicationRole($membership->role);
                    $roleId = $roleCode ? $roles->get($roleCode) : null;
                    if (! $roleId) continue;

                    $this->assign((int) $membership->user_id, (int) $roleId, (int) $membership->application_id, null, [
                        'source' => 'application_user',
                        'legacy_membership_id' => (int) $membership->id,
                        'legacy_role' => $membership->role,
                        'migrated_at' => now()->toIso8601String(),
                    ]);
                }
            });
    }

    private function encryptLegacyPartyDocuments(): void
    {
        Party::query()
            ->whereNotNull('document')
            ->whereNull('document_encrypted')
            ->orderBy('id')
            ->chunkById(200, function ($parties) {
                foreach ($parties as $party) {
                    $legacy = $party->getRawOriginal('document');
                    if (! $legacy) continue;
                    $party->document = $legacy;
                    $party->save();
                }
            });
    }

    private function resolveApplicationId(int $establishmentId, ?int $applicationId): ?int
    {
        if ($applicationId) return $applicationId;
        $ids = DB::table('application_establishment')
            ->where('establishment_id', $establishmentId)
            ->limit(2)
            ->pluck('application_id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function membership(int $userId, int $establishmentId, array $metadata): void
    {
        Membership::query()->firstOrCreate(
            ['user_id' => $userId, 'establishment_id' => $establishmentId],
            ['status' => 'active', 'metadata' => $metadata],
        );
    }

    private function assign(int $userId, int $roleId, ?int $applicationId, ?int $establishmentId, array $metadata): void
    {
        if ($establishmentId && ! $applicationId) return;
        $contextKey = RoleAssignment::contextKey($applicationId, $establishmentId);
        RoleAssignment::query()->firstOrCreate(
            ['user_id' => $userId, 'role_id' => $roleId, 'context_key' => $contextKey],
            ['application_id' => $applicationId, 'establishment_id' => $establishmentId, 'status' => 'active', 'metadata' => $metadata],
        );
    }

    private function mapEmploymentRole(?string $legacyRole): string
    {
        return match ($this->normalize($legacyRole)) {
            'owner', 'proprietario', 'proprietaria', 'socio', 'socia' => 'owner',
            'manager', 'gerente', 'gestor', 'gestora', 'administrator', 'administrador', 'administradora' => 'manager',
            'operator', 'operador', 'operadora', 'atendente' => 'operator',
            'financial_manager', 'financeiro', 'financeira' => 'financial_manager',
            default => 'employee',
        };
    }

    private function mapApplicationRole(?string $legacyRole): ?string
    {
        return match ($this->normalize($legacyRole)) {
            'super_admin', 'superadmin' => 'super_admin',
            'admin', 'administrator', 'administrador', 'administradora' => 'administrator',
            'owner', 'proprietario', 'proprietaria' => 'owner',
            'manager', 'gerente', 'gestor', 'gestora' => 'manager',
            'employee', 'colaborador', 'colaboradora' => 'employee',
            'operator', 'operador', 'operadora', 'atendente' => 'operator',
            'financial_manager', 'financeiro', 'financeira' => 'financial_manager',
            'viewer', 'visualizador', 'visualizadora' => 'viewer',
            default => null,
        };
    }

    private function normalize(?string $value): string
    {
        return Str::of((string) $value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }
}
