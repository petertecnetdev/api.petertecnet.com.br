<?php

namespace App\Services\Admin;

use App\Models\EcosystemAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminUserSecurityService
{
    public function revokeSessions(User $target, Request $request): array
    {
        $before = max((int) $target->auth_version, 1);
        $target->forceFill(['auth_version' => $before + 1])->save();

        if (Schema::hasTable('ecosystem_audit_logs')) {
            EcosystemAuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'user.sessions.revoked',
                'entity_type' => User::class,
                'entity_id' => $target->id,
                'before' => ['auth_version' => $before],
                'after' => ['auth_version' => $before + 1],
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            ]);
        }

        return [
            'message' => 'Sessões e tokens JWT anteriores foram invalidados.',
            'revoked_at' => now()->toIso8601String(),
        ];
    }
}
