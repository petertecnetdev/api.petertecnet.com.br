<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Facades\DB;

final class LeaseContextService
{
    public function contexts(int $appId, int $userId, string $email): array
    {
        $email = $this->normalizedEmail($email);
        $propertyCount = DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
        $landlordLeaseCount = $this->leaseQuery($appId, $userId, $email, 'landlord')->count();
        $tenantLeaseCount = $this->leaseQuery($appId, $userId, $email, 'tenant')->count();
        $contexts = [];

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

    public function dashboard(int $appId, int $userId, string $email, string $role): array
    {
        $contexts = $this->contexts($appId, $userId, $email);
        abort_unless(collect($contexts)->contains(fn (array $context) => $context['key'] === $role), 403, 'Este contexto não está disponível para sua conta.');

        $leaseIds = $this->leaseQuery($appId, $userId, $this->normalizedEmail($email), $role)->pluck('id');
        $charges = DB::table('lease_charges')->where('app_id', $appId)->whereIn('lease_id', $leaseIds);

        return [
            'context_role' => $role,
            'properties' => $role === 'landlord'
                ? DB::table('properties')->where('app_id', $appId)->where('owner_user_id', $userId)->whereNull('deleted_at')->count()
                : 0,
            'active_leases' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'active')->whereNull('deleted_at')->count(),
            'awaiting_signature' => DB::table('leases')->where('app_id', $appId)->whereIn('id', $leaseIds)->where('status', 'awaiting_signature')->whereNull('deleted_at')->count(),
            'pending_amount' => round((float) (clone $charges)->whereIn('status', ['pending', 'processing'])->sum('amount'), 2),
            'overdue_amount' => round((float) (clone $charges)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', today())->sum('amount'), 2),
            'received_this_year' => round((float) (clone $charges)->where('status', 'paid')->whereYear('paid_at', now()->year)->sum('amount'), 2),
            'next_charges' => DB::table('lease_charges')
                ->where('app_id', $appId)
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('due_date')
                ->limit(8)
                ->get(),
        ];
    }

    public function defaultRole(array $contexts): string
    {
        return collect($contexts)->contains(fn (array $context) => $context['key'] === 'landlord')
            ? 'landlord'
            : ($contexts[0]['key'] ?? 'landlord');
    }

    private function leaseQuery(int $appId, int $userId, string $email, string $role)
    {
        $query = DB::table('leases')->where('app_id', $appId)->whereNull('deleted_at');

        if ($role === 'landlord') {
            return $query->where('landlord_user_id', $userId);
        }

        return $query->where(function ($scope) use ($userId, $email) {
            $scope->where('tenant_user_id', $userId);
            if ($email !== '') {
                $scope->orWhere(function ($pending) use ($email) {
                    $pending->whereNull('tenant_user_id')->whereRaw('LOWER(tenant_email) = ?', [$email]);
                });
            }
        });
    }

    private function normalizedEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
