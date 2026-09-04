<?php

namespace App\Domain\Assets\Services;

use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AssetAccessService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function assertAccess(Request $request, string $assetType, int $assetId, string $permission = 'view'): object
    {
        $asset = $this->asset($assetType, $assetId);
        $user = $request->user();
        abort_unless($user, 401);

        $ownerId = $this->ownerUserId($assetType, $asset);
        $this->ensurePrimaryOwnership($assetType, $assetId, $ownerId);

        if ((int) $ownerId === (int) $user->id || $this->isAdmin($user)) {
            return $asset;
        }

        $ownership = DB::table('asset_ownerships')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today());
            })
            ->first();

        if ($ownership && $this->allows($ownership->role, $ownership->permissions, $permission)) {
            return $asset;
        }

        $grant = DB::table('asset_access_grants')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($user) {
                $query->where('grantee_user_id', $user->id)
                    ->orWhere(function ($emailQuery) use ($user) {
                        $emailQuery->whereNull('grantee_user_id')->where('grantee_email', $user->email);
                    });
            })
            ->orderByDesc('id')
            ->first();

        abort_unless($grant && $this->allows($grant->role, $grant->permissions, $permission), 403, 'Você não tem permissão para executar esta ação neste patrimônio.');

        return $asset;
    }

    public function permissions(Request $request, string $assetType, int $assetId): array
    {
        $asset = $this->asset($assetType, $assetId);
        $user = $request->user();
        if (! $user) return [];
        $ownerId = $this->ownerUserId($assetType, $asset);
        $this->ensurePrimaryOwnership($assetType, $assetId, $ownerId);
        if ((int) $ownerId === (int) $user->id || $this->isAdmin($user)) return ['*'];

        $ownership = DB::table('asset_ownerships')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->where('user_id', $user->id)
            ->first();
        if ($ownership) return $this->permissionList($ownership->role, $ownership->permissions);

        $grant = DB::table('asset_access_grants')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($user) {
                $query->where('grantee_user_id', $user->id)->orWhere('grantee_email', $user->email);
            })
            ->orderByDesc('id')
            ->first();

        return $grant ? $this->permissionList($grant->role, $grant->permissions) : [];
    }

    public function accessibleAssetIds(Request $request, string $assetType): array
    {
        $user = $request->user();
        if (! $user) return [];
        $appId = $this->context->id();
        $ids = collect();

        if ($assetType === 'property') {
            $ids = $ids->merge(DB::table('properties')
                ->where('app_id', $appId)
                ->where('owner_user_id', $user->id)
                ->whereNull('deleted_at')
                ->pluck('id'));
        }

        $ids = $ids->merge(DB::table('asset_ownerships')
            ->where('app_id', $appId)
            ->where('asset_type', $assetType)
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', today());
            })
            ->pluck('asset_id'));

        $ids = $ids->merge(DB::table('asset_access_grants')
            ->where('app_id', $appId)
            ->where('asset_type', $assetType)
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) use ($user) {
                $query->where('grantee_user_id', $user->id)->orWhere('grantee_email', $user->email);
            })
            ->pluck('asset_id'));

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function publicGrant(string $token): object
    {
        abort_unless(strlen($token) >= 32, 404);
        $grant = DB::table('asset_access_grants')
            ->where('app_id', $this->context->id())
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
        abort_unless($grant, 404, 'Compartilhamento inválido ou expirado.');
        return $grant;
    }

    public function touchGrant(object $grant): void
    {
        DB::table('asset_access_grants')->where('id', $grant->id)->update([
            'last_accessed_at' => now(),
            'access_count' => DB::raw('access_count + 1'),
            'updated_at' => now(),
        ]);
    }

    public function asset(string $assetType, int $assetId): object
    {
        abort_unless($assetType === 'property', 422, 'Tipo de ativo ainda não registrado no resolvedor genérico.');
        $asset = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('id', $assetId)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($asset, 404, 'Patrimônio não encontrado.');
        return $asset;
    }

    public function ensurePrimaryOwnership(string $assetType, int $assetId, int $ownerUserId): void
    {
        DB::table('asset_ownerships')->updateOrInsert([
            'app_id' => $this->context->id(),
            'asset_type' => $assetType,
            'asset_id' => $assetId,
            'user_id' => $ownerUserId,
        ], [
            'role' => 'owner',
            'share_percent' => 100,
            'is_primary' => true,
            'permissions' => json_encode(['*']),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function roleDefaults(): array
    {
        return [
            'owner' => ['*'],
            'co_owner' => ['view', 'edit', 'financial', 'maintenance', 'inspections', 'files', 'inventory', 'preventive', 'report', 'share', 'owners', 'audit'],
            'manager' => ['view', 'edit', 'financial', 'maintenance', 'inspections', 'files', 'inventory', 'preventive', 'report', 'audit'],
            'accountant' => ['view', 'financial', 'report', 'audit'],
            'lawyer' => ['view', 'files', 'report', 'audit'],
            'realtor' => ['view', 'files', 'report'],
            'provider' => ['view', 'maintenance', 'files'],
            'tenant' => ['view', 'inspections', 'files'],
            'viewer' => ['view', 'report'],
        ];
    }

    private function ownerUserId(string $assetType, object $asset): int
    {
        return match ($assetType) {
            'property' => (int) $asset->owner_user_id,
            default => 0,
        };
    }

    private function allows(string $role, mixed $storedPermissions, string $permission): bool
    {
        $permissions = $this->permissionList($role, $storedPermissions);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function permissionList(string $role, mixed $storedPermissions): array
    {
        $defaults = $this->roleDefaults()[$role] ?? ['view'];
        $stored = $this->decode($storedPermissions);
        if ($stored === []) return $defaults;
        return array_values(array_unique(array_merge($defaults, array_filter($stored, 'is_string'))));
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isAdmin(object $user): bool
    {
        try {
            return (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador'))
                || (method_exists($user, 'hasPermission') && $user->hasPermission('admin_access'));
        } catch (\Throwable) {
            return false;
        }
    }
}
