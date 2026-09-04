<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeasingPolicyAccessController extends Controller
{
    private const ROLES = ['owner', 'tenant', 'manager', 'accountant', 'proxy', 'viewer'];
    private const PERMISSIONS = ['view', 'manage', 'contracts', 'documents', 'finance', 'inspections', 'maintenance', 'reports'];

    public function __construct(private readonly ApplicationContext $context) {}

    public function policy(Request $request)
    {
        $row = DB::table('leasing_policies')->where('app_id', $this->context->id())->first();
        return response()->json(['settings' => $row ? $this->decode($row->settings) : $this->defaults()]);
    }

    public function updatePolicy(Request $request)
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'renewal_alert_days' => 'nullable|array|max:10', 'renewal_alert_days.*' => 'integer|min:1|max:730',
            'charge_reminder_days' => 'nullable|array|max:20', 'charge_reminder_days.*' => 'integer|min:-90|max:365',
            'required_documents' => 'nullable|array|max:30', 'required_documents.*' => 'string|max:64',
            'max_deposit_months' => 'nullable|integer|min:0|max:3',
            'signature_parties' => 'nullable|array|min:1|max:4', 'signature_parties.*' => 'string|max:32',
            'document_retention_days' => 'nullable|integer|min:30|max:36500',
            'allow_overlapping_drafts' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);
        $settings = array_merge($this->defaults(), $data['settings'] ?? [], array_diff_key($data, ['settings' => true]));
        $settings['renewal_alert_days'] = array_values(array_unique(array_map('intval', $settings['renewal_alert_days'])));
        rsort($settings['renewal_alert_days']);
        DB::table('leasing_policies')->updateOrInsert(
            ['app_id' => $this->context->id()],
            ['settings' => $this->json($settings), 'updated_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['settings' => $settings]);
    }

    public function grants(Request $request)
    {
        $userId = (int) $request->user()->id;
        $isAdmin = $this->isAdmin($request);
        $query = DB::table('leasing_access_grants')->where('app_id', $this->context->id())->whereNull('revoked_at');
        if (! $isAdmin) {
            $ownedProperties = DB::table('properties')->where('app_id', $this->context->id())->where('owner_user_id', $userId)->whereNull('deleted_at')->pluck('id');
            $ownedLeases = DB::table('leases')->where('app_id', $this->context->id())->where('landlord_user_id', $userId)->whereNull('deleted_at')->pluck('id');
            $query->where(fn ($q) => $q->whereIn('property_id', $ownedProperties)->orWhereIn('lease_id', $ownedLeases)->orWhere('user_id', $userId));
        }
        return response()->json($query->orderByDesc('id')->get()->map(fn ($row) => $this->grantPayload($row)));
    }

    public function storeGrant(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id', 'property_id' => 'nullable|integer', 'lease_id' => 'nullable|integer',
            'role' => 'required|in:'.implode(',', self::ROLES), 'permissions' => 'nullable|array|max:20',
            'permissions.*' => 'string|in:'.implode(',', self::PERMISSIONS), 'expires_at' => 'nullable|date|after:now',
        ]);
        abort_if(empty($data['property_id']) && empty($data['lease_id']), 422, 'Informe o imóvel ou a locação do acesso.');
        $this->assertCanGrant($request, $data['property_id'] ?? null, $data['lease_id'] ?? null);
        if (! empty($data['lease_id'])) {
            $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $data['lease_id'])->whereNull('deleted_at')->firstOrFail();
            if (! empty($data['property_id'])) abort_unless((int) $lease->property_id === (int) $data['property_id'], 422, 'A locação não pertence ao imóvel informado.');
        }
        $id = DB::table('leasing_access_grants')->insertGetId([
            'app_id' => $this->context->id(), 'property_id' => $data['property_id'] ?? null, 'lease_id' => $data['lease_id'] ?? null,
            'user_id' => $data['user_id'], 'granted_by_user_id' => $request->user()->id, 'role' => $data['role'],
            'permissions' => $this->json(array_values(array_unique($data['permissions'] ?? $this->roleDefaults($data['role'])))),
            'expires_at' => $data['expires_at'] ?? null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json($this->grantPayload(DB::table('leasing_access_grants')->where('id', $id)->first()), 201);
    }

    public function revokeGrant(Request $request, int $grantId)
    {
        $grant = DB::table('leasing_access_grants')->where('app_id', $this->context->id())->where('id', $grantId)->whereNull('revoked_at')->firstOrFail();
        $this->assertCanGrant($request, $grant->property_id, $grant->lease_id);
        DB::table('leasing_access_grants')->where('id', $grantId)->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    private function assertCanGrant(Request $request, ?int $propertyId, ?int $leaseId): void
    {
        if ($this->isAdmin($request)) return;
        $userId = (int) $request->user()->id;
        if ($propertyId) {
            $owned = DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->where('owner_user_id', $userId)->whereNull('deleted_at')->exists();
            abort_unless($owned, 403);
            return;
        }
        $owned = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->where('landlord_user_id', $userId)->whereNull('deleted_at')->exists();
        abort_unless($owned, 403);
    }

    private function defaults(): array
    {
        return [
            'renewal_alert_days' => [90, 30, 7],
            'charge_reminder_days' => [-5, 0, 3, 7, 15],
            'required_documents' => ['identity', 'address'],
            'max_deposit_months' => 3,
            'signature_parties' => ['landlord', 'tenant'],
            'document_retention_days' => 3650,
            'allow_overlapping_drafts' => false,
        ];
    }

    private function roleDefaults(string $role): array
    {
        return match ($role) {
            'owner', 'manager' => self::PERMISSIONS,
            'proxy' => ['view', 'manage', 'contracts', 'documents', 'inspections', 'maintenance'],
            'accountant' => ['view', 'finance', 'reports'],
            'tenant' => ['view', 'documents', 'inspections'],
            default => ['view'],
        };
    }

    private function grantPayload(object $row): array
    {
        $data = (array) $row;
        $data['permissions'] = $this->decode($row->permissions);
        $data['active'] = $row->revoked_at === null && ($row->expires_at === null || strtotime($row->expires_at) > time());
        return $data;
    }

    private function assertAdmin(Request $request): void { abort_unless($this->isAdmin($request), 403); }
    private function isAdmin(Request $request): bool { return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'); }
    private function decode($value): array { if (is_array($value)) return $value; if (! $value) return []; $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : []; }
    private function json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
