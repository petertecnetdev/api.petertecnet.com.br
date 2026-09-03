<?php

namespace App\Services\Reporting;

use App\Models\User;

class ReportDataMasker
{
    public function apply(array $report, ?User $user): array
    {
        if (! $user || $user->hasProfile('Administrador')) return $report;

        $key = $report['key'] ?? '';
        $canSeeIdentity = $user->hasPermission('user_management');
        $canSeeAudit = $user->hasPermission('audit_view');
        $canSeeFinance = $user->hasPermission('finance_view');

        $report['rows'] = collect($report['rows'] ?? [])->map(function (array $row) use ($key, $canSeeIdentity, $canSeeAudit, $canSeeFinance) {
            foreach ($row as $field => $value) {
                if (in_array($field, ['email', 'owner', 'administrator'], true) && ! ($canSeeIdentity || ($key === 'audit' && $canSeeAudit))) $row[$field] = $this->email((string) $value);
                if ($field === 'document' && ! $canSeeIdentity) $row[$field] = $this->document((string) $value);
                if ($field === 'ip' && ! $canSeeAudit) $row[$field] = $this->ip((string) $value);
                if ($field === 'reference' && $key === 'financial' && ! $canSeeFinance) $row[$field] = $this->generic((string) $value);
            }
            return $row;
        })->all();

        return $report;
    }

    private function email(string $value): string
    {
        if (! str_contains($value, '@')) return $this->generic($value);
        [$local, $domain] = explode('@', $value, 2);
        return mb_substr($local, 0, 2) . '***@' . $domain;
    }

    private function document(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        return strlen($digits) > 4 ? str_repeat('*', max(strlen($digits) - 4, 4)) . substr($digits, -4) : '****';
    }

    private function ip(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $value);
            return $parts[0] . '.' . $parts[1] . '.*.*';
        }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return substr($value, 0, 8) . '::****';
        return $this->generic($value);
    }

    private function generic(string $value): string
    {
        return mb_strlen($value) > 6 ? mb_substr($value, 0, 3) . '***' . mb_substr($value, -2) : '******';
    }
}
