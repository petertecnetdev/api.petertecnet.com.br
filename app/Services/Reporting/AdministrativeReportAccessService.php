<?php

namespace App\Services\Reporting;

use App\Models\User;

class AdministrativeReportAccessService
{
    public function assertManage(?User $user, ?string $report = null): void
    {
        abort_unless($user && $this->canManage($user), 403, 'Usuário sem permissão para gerar relatórios administrativos.');
        abort_unless($this->canAccess($user, $report), 403, 'Usuário sem permissão para gerar este relatório administrativo.');
    }

    public function canManage(User $user): bool
    {
        return $user->hasProfile('Administrador')
            || $user->hasPermission('ecosystem_manage')
            || $user->hasPermission('application_manage')
            || $user->hasPermission('user_management')
            || $user->hasPermission('permission_management');
    }

    public function canAccess(User $user, ?string $report): bool
    {
        if ($report === null || $report === '' || $user->hasProfile('Administrador')) return true;
        if ($report === 'audit') return $user->hasPermission('audit_view');
        if ($report === 'financial') return $user->hasPermission('finance_view');
        return true;
    }
}
