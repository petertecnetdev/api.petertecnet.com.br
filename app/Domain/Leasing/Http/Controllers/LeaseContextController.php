<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseContextController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(Request $request)
    {
        $contexts = $this->contextsFor($request);
        $default = collect($contexts)->contains(fn (array $context) => $context['key'] === 'landlord')
            ? 'landlord'
            : ($contexts[0]['key'] ?? 'landlord');

        return response()->json([
            'contexts' => $contexts,
            'default_context' => $default,
            'multiple_contexts' => count($contexts) > 1,
        ]);
    }

    public function dashboard(Request $request)
    {
        $role = $this->requestedRole($request);
        $contexts = $this->contextsFor($request);
        $allowed = collect($contexts)->contains(fn (array $context) => $context['key'] === $role);
        abort_unless($allowed, 403, 'Este contexto não está disponível para sua conta.');

        $userId = (int) $request->user()->id;
        $email = $this->normalizedEmail($request);
        $appId = $this->context->id();
        $leaseIds = $this->leaseQuery($userId, $email, $role)->pluck('id');

        $charges = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds);

        $pending = (clone $charges)->whereIn('status', ['pending', 'processing'])->sum('amount');
        $overdue = (clone $charges)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', today())->sum('amount');
        $received = (clone $charges)->where('status', 'paid')->whereYear('paid_at', now()->year)->sum('amount');

        return response()->json([
            'context_role' => $role,
            'properties' => $role === 'landlord'
                ? DB::table('properties')->where('app_id', $appId)->where('owner_user_id', $userId)->whereNull('deleted_at')->count()
                : 0,
            'active_leases' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'active')->whereNull('deleted_at')->count(),
            'awaiting_signature' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'awaiting_signature')->whereNull('deleted_at')->count(),
            'pending_amount' => round((float) $pending, 2),
            'overdue_amount' => round((float) $overdue, 2),
            'received_this_year' => round((float) $received, 2),
            'next_charges' => DB::table('lease_charges')
                ->where('app_id', $appId)
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('due_date')
                ->limit(8)
                ->get(),
        ]);
    }

    private function contextsFor(Request $request): array
    {
        $appId = $this->context->id();
        $userId = (int) $request->user()->id;
        $email = $this->normalizedEmail($request);

        $propertyCount = DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
        $landlordLeaseCount = $this->leaseQuery($userId, $email, 'landlord')->count();
        $tenantLeaseCount = $this->leaseQuery($userId, $email, 'tenant')->count();

        $contexts = [];

        // A conta sem vínculos inicia como administradora de patrimônio para poder cadastrar o primeiro imóvel.
        if ($propertyCount > 0 || $landlordLeaseCount > 0 || $tenantLeaseCount === 0) {
            $contexts[] = [
                'key' => 'landlord',
                'label' => 'Proprietário',
                'description' => 'Gerencie imóveis, locações, contratos e recebimentos.',
                'counts' => ['properties' => $propertyCount, 'leases' => $landlordLeaseCount],
                'capabilities' => [
                    'properties.read', 'properties.write', 'leases.read', 'leases.manage',
                    'contracts.read', 'contracts.manage', 'charges.read', 'charges.manage',
                    'documents.read', 'documents.manage', 'portfolio.read',
                ],
            ];
        }

        if ($tenantLeaseCount > 0) {
            $contexts[] = [
                'key' => 'tenant',
                'label' => 'Inquilino',
                'description' => 'Acompanhe sua locação, contrato, documentos e pagamentos.',
                'counts' => ['properties' => 0, 'leases' => $tenantLeaseCount],
                'capabilities' => [
                    'leases.read', 'contracts.read', 'contracts.sign', 'charges.read', 'charges.pay',
                    'documents.read', 'documents.write', 'maintenance.read', 'maintenance.create',
                ],
            ];
        }

        return $contexts;
    }

    private function leaseQuery(int $userId, string $email, string $role)
    {
        $query = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->whereNull('deleted_at');

        if ($role === 'landlord') {
            return $query->where('landlord_user_id', $userId);
        }

        return $query->where(function ($scope) use ($userId, $email) {
            $scope->where('tenant_user_id', $userId);
            if ($email !== '') {
                $scope->orWhere(function ($pending) use ($email) {
                    $pending->whereNull('tenant_user_id')
                        ->whereRaw('LOWER(tenant_email) = ?', [$email]);
                });
            }
        });
    }

    private function requestedRole(Request $request): string
    {
        $role = strtolower(trim((string) ($request->query('role') ?: $request->header('X-Peter-Context-Role', ''))));
        return in_array($role, ['landlord', 'tenant'], true) ? $role : 'landlord';
    }

    private function normalizedEmail(Request $request): string
    {
        return mb_strtolower(trim((string) ($request->user()->email ?? '')));
    }
}
