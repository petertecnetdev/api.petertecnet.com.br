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

        // Uma única agregação substitui duas contagens separadas de locações.
        // Isso é particularmente importante porque este endpoint participa do boot.
        $leaseCounts = DB::table('leases')
            ->where('app_id', $appId)
            ->whereNull('deleted_at')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN landlord_user_id = ? THEN 1 ELSE 0 END), 0) as landlord_count',
                [$userId],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN tenant_user_id = ? OR (tenant_user_id IS NULL AND LOWER(tenant_email) = ?) THEN 1 ELSE 0 END), 0) as tenant_count',
                [$userId, $email],
            )
            ->first();

        $landlordLeaseCount = (int) ($leaseCounts->landlord_count ?? 0);
        $tenantLeaseCount = (int) ($leaseCounts->tenant_count ?? 0);
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
        $email = $this->normalizedEmail($email);
        $contexts = $this->contexts($appId, $userId, $email);
        $activeContext = collect($contexts)->firstWhere('key', $role);
        abort_unless($activeContext, 403, 'Este contexto não está disponível para sua conta.');

        // Traz id e status em uma única consulta. As contagens de status passam a
        // ser feitas na pequena coleção do usuário em vez de voltar ao banco.
        $leases = $this->leaseQuery($appId, $userId, $email, $role)
            ->select(['id', 'status'])
            ->get();
        $leaseIds = $leases->pluck('id');

        $payload = [
            'context_role' => $role,
            'properties' => $role === 'landlord' ? (int) ($activeContext['counts']['properties'] ?? 0) : 0,
            'active_leases' => $leases->where('status', 'active')->count(),
            'awaiting_signature' => $leases->where('status', 'awaiting_signature')->count(),
            'pending_amount' => 0.0,
            'overdue_amount' => 0.0,
            'received_this_year' => 0.0,
            'next_charges' => collect(),
        ];

        if ($leaseIds->isEmpty()) return $payload;

        $yearStart = now()->startOfYear();
        $nextYear = $yearStart->copy()->addYear();
        $today = today();

        // Três SUMs independentes viraram uma única agregação condicional.
        $chargeSummary = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN amount ELSE 0 END), 0) as pending_amount")
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') AND due_date < ? THEN amount ELSE 0 END), 0) as overdue_amount",
                [$today],
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= ? AND paid_at < ? THEN amount ELSE 0 END), 0) as received_this_year",
                [$yearStart, $nextYear],
            )
            ->first();

        $payload['pending_amount'] = round((float) ($chargeSummary->pending_amount ?? 0), 2);
        $payload['overdue_amount'] = round((float) ($chargeSummary->overdue_amount ?? 0), 2);
        $payload['received_this_year'] = round((float) ($chargeSummary->received_this_year ?? 0), 2);
        $payload['next_charges'] = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        return $payload;
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
