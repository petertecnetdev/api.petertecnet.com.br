<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AssetSharingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
    ) {}

    public function index(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'share');
        $items = DB::table('asset_access_grants as g')
            ->leftJoin('users as u', 'u.id', '=', 'g.grantee_user_id')
            ->where('g.app_id', $this->context->id())
            ->where('g.asset_type', $assetType)
            ->where('g.asset_id', $assetId)
            ->orderByDesc('g.id')
            ->get(['g.*', 'u.first_name', 'u.last_name', 'u.email as user_email'])
            ->map(fn ($row) => $this->grantPayload($row));
        return response()->json(['items' => $items, 'role_defaults' => $this->access->roleDefaults()]);
    }

    public function store(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'share');
        $data = $request->validate([
            'grantee_user_id' => 'nullable|integer|exists:users,id',
            'grantee_email' => 'nullable|email|max:190',
            'role' => 'required|in:manager,accountant,lawyer,realtor,provider,tenant,viewer',
            'permissions' => 'nullable|array|max:30',
            'permissions.*' => 'string|max:60',
            'expires_at' => 'required|date|after:now|before_or_equal:'.now()->addYear()->toDateTimeString(),
            'metadata' => 'nullable|array',
        ]);
        abort_if(empty($data['grantee_user_id']) && empty($data['grantee_email']), 422, 'Informe um usuário ou e-mail para o compartilhamento.');

        $rawToken = Str::random(64);
        $id = DB::table('asset_access_grants')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId,
            'created_by_user_id' => $request->user()->id, 'grantee_user_id' => $data['grantee_user_id'] ?? null,
            'grantee_email' => isset($data['grantee_email']) ? mb_strtolower(trim($data['grantee_email'])) : null,
            'role' => $data['role'], 'permissions' => isset($data['permissions']) ? $this->json($data['permissions']) : null,
            'token_hash' => hash('sha256', $rawToken), 'expires_at' => $data['expires_at'], 'metadata' => $this->json($data['metadata'] ?? null),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('asset_access_grants')->where('id', $id)->first();
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'share_created', 'Acesso temporário criado', $row->grantee_email ?: 'Usuário #'.$row->grantee_user_id, null, $this->grantPayload($row), ['grant_id' => $id]);
        return response()->json([
            'item' => $this->grantPayload($row),
            'token' => $rawToken,
            'public_api_path' => "/v1/apps/{$request->route('application')}/shared-assets/{$rawToken}",
        ], 201);
    }

    public function revoke(Request $request, string $assetType, int $assetId, int $grantId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'share');
        $row = DB::table('asset_access_grants')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $grantId)->first();
        abort_unless($row, 404, 'Compartilhamento não encontrado.');
        DB::table('asset_access_grants')->where('id', $grantId)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'share_revoked', 'Acesso temporário revogado', $row->grantee_email ?: 'Usuário #'.$row->grantee_user_id, $this->grantPayload($row), null, ['grant_id' => $grantId]);
        return response()->json(['ok' => true]);
    }

    public function sharedView(Request $request, string $token)
    {
        $grant = $this->access->publicGrant($token);
        $permissions = $this->grantPermissions($grant);
        abort_unless(in_array('*', $permissions, true) || in_array('view', $permissions, true), 403);
        $asset = $this->access->asset($grant->asset_type, (int) $grant->asset_id);
        $this->access->touchGrant($grant);

        $response = [
            'share' => [
                'public_id' => $grant->public_id, 'role' => $grant->role, 'permissions' => $permissions,
                'expires_at' => $grant->expires_at, 'grantee_email' => $grant->grantee_email,
            ],
            'asset' => $this->safeAssetPayload($grant->asset_type, $asset),
            'health' => $this->intelligence->health($grant->asset_type, (int) $grant->asset_id),
        ];

        if ($this->allowed($permissions, 'financial')) $response['financial'] = $this->intelligence->financial($grant->asset_type, (int) $grant->asset_id);
        if ($this->allowed($permissions, 'files')) {
            $response['files'] = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $grant->asset_type)->where('entity_id', $grant->asset_id)->where('status', 'active')->orderByDesc('is_primary')->orderByDesc('id')->get()->map(fn ($file) => $this->filePayload($file));
        }
        if ($this->allowed($permissions, 'inspections') && $grant->asset_type === 'property') {
            $response['inspections'] = DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $grant->asset_id)->orderByDesc('occurred_at')->get()->map(function ($row) { $data = (array) $row; $data['items'] = $this->decode($row->items); $data['metadata'] = $this->decode($row->metadata); return $data; });
        }
        if ($this->allowed($permissions, 'maintenance') && $grant->asset_type === 'property') {
            $response['maintenance'] = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $grant->asset_id)->where('type', 'maintenance')->whereNull('deleted_at')->orderByDesc('id')->get()->map(function ($row) { $data = (array) $row; $data['payload'] = $this->decode($row->payload); return $data; });
        }
        if ($this->allowed($permissions, 'inventory')) {
            $response['inventory'] = DB::table('asset_inventory_items')->where('app_id', $this->context->id())->where('asset_type', $grant->asset_type)->where('asset_id', $grant->asset_id)->whereNull('deleted_at')->orderBy('name')->get();
        }

        return response()->json($response);
    }

    public function sharedFile(Request $request, string $token, int $fileId)
    {
        $grant = $this->access->publicGrant($token);
        $permissions = $this->grantPermissions($grant);
        abort_unless($this->allowed($permissions, 'files'), 403, 'Este compartilhamento não permite acessar arquivos.');
        $file = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $grant->asset_type)->where('entity_id', $grant->asset_id)->where('id', $fileId)->where('status', 'active')->first();
        abort_unless($file, 404, 'Arquivo não encontrado.');
        abort_if($file->storage === 'external', 422, 'Arquivo externo não disponível por este endpoint.');
        $disk = $file->storage ?: 'local';
        abort_unless(Storage::disk($disk)->exists($file->path), 404, 'Arquivo não encontrado no armazenamento.');
        $this->access->touchGrant($grant);
        return Storage::disk($disk)->download($file->path, basename((string) ($file->original_name ?: 'arquivo')));
    }

    private function grantPayload(object $row): array
    {
        $data = (array) $row;
        unset($data['token_hash']);
        $data['permissions'] = $this->decode($row->permissions);
        $data['metadata'] = $this->decode($row->metadata);
        $data['active'] = ! $row->revoked_at && (! $row->expires_at || now()->lt($row->expires_at));
        $data['display_name'] = trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: ($row->user_email ?? $row->grantee_email ?? 'Acesso temporário');
        return $data;
    }

    private function grantPermissions(object $grant): array
    {
        $defaults = $this->access->roleDefaults()[$grant->role] ?? ['view'];
        return array_values(array_unique(array_merge($defaults, array_filter($this->decode($grant->permissions), 'is_string'))));
    }

    private function safeAssetPayload(string $assetType, object $asset): array
    {
        if ($assetType === 'property') {
            return [
                'id' => $asset->id, 'name' => $asset->name, 'type' => $asset->type, 'use_type' => $asset->use_type, 'status' => $asset->status,
                'street' => $asset->street, 'number' => $asset->number, 'complement' => $asset->complement, 'neighborhood' => $asset->neighborhood,
                'city' => $asset->city, 'state' => $asset->state, 'postal_code' => $asset->postal_code, 'bedrooms' => $asset->bedrooms,
                'bathrooms' => $asset->bathrooms, 'parking_spaces' => $asset->parking_spaces, 'area_m2' => $asset->area_m2,
            ];
        }
        return ['id' => $asset->id];
    }

    private function filePayload(object $file): array
    {
        $meta = $this->decode($file->meta);
        return [
            'id' => $file->id, 'original_name' => $file->original_name, 'mime_type' => $file->mime_type, 'file_size' => (int) ($file->file_size ?? 0),
            'group' => $file->group, 'is_primary' => (bool) $file->is_primary, 'created_at' => $file->created_at,
            'context' => $meta['context'] ?? null, 'occurred_on' => $meta['occurred_on'] ?? null,
        ];
    }

    private function allowed(array $permissions, string $permission): bool { return in_array('*', $permissions, true) || in_array($permission, $permissions, true); }
    private function decode(mixed $value): array { if (is_array($value)) return $value; if ($value === null || $value === '') return []; $d = json_decode((string) $value, true); return is_array($d) ? $d : []; }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
