<?php

namespace App\Services\Admin;

use App\Models\EcosystemAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminUserAccountAccessService
{
    public function set(User $target, string $status, Request $request): array
    {
        $before = DB::table('application_user')
            ->where('user_id', $target->id)
            ->pluck('status', 'application_id')
            ->all();

        if ($status === 'blocked') {
            DB::table('application_user')
                ->where('user_id', $target->id)
                ->where('status', '!=', 'blocked')
                ->update(['status' => 'blocked', 'updated_at' => now()]);

            $target->forceFill([
                'auth_version' => max((int) $target->auth_version, 1) + 1,
            ])->save();
        } else {
            // Reativação administrativa não promove vínculos que já eram pendentes ou suspensos.
            // Somente os vínculos bloqueados pela operação global voltam a ativo.
            DB::table('application_user')
                ->where('user_id', $target->id)
                ->where('status', 'blocked')
                ->update(['status' => 'active', 'updated_at' => now()]);
        }

        $after = DB::table('application_user')
            ->where('user_id', $target->id)
            ->pluck('status', 'application_id')
            ->all();

        if (Schema::hasTable('ecosystem_audit_logs')) {
            EcosystemAuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'user.account_access.updated',
                'entity_type' => User::class,
                'entity_id' => $target->id,
                'before' => ['applications' => $before],
                'after' => ['applications' => $after, 'requested_status' => $status],
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        }

        return [
            'message' => $status === 'blocked'
                ? 'Acesso bloqueado nas aplicações atualmente liberadas e sessões anteriores invalidadas.'
                : 'Vínculos bloqueados foram reativados sem alterar vínculos pendentes ou suspensos.',
            'status' => $status,
            'applications_affected' => collect($before)->filter(
                fn ($previous, $applicationId) => ($after[$applicationId] ?? null) !== $previous
            )->count(),
        ];
    }
}
