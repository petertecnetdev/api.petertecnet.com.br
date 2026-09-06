<?php

namespace App\Domain\Platform\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationAdminOverviewService
{
    public function overview(int $applicationId): array
    {
        return [
            'users' => $this->applicationUsers($applicationId),
            'establishments' => $this->countScopedTable('establishments', $applicationId),
            'events' => $this->countScopedTable('events', $applicationId),
            'orders' => $this->countScopedTable('orders', $applicationId),
            'active_admins' => $this->activeAdmins($applicationId),
            'admin_profiles' => $this->adminProfiles($applicationId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function applicationUsers(int $applicationId): ?int
    {
        if (! Schema::hasTable('application_user')) {
            return null;
        }

        $query = DB::table('application_user')->where('application_id', $applicationId);
        if (Schema::hasColumn('application_user', 'status')) {
            $query->where('status', 'active');
        }

        return $query->distinct()->count('user_id');
    }

    private function activeAdmins(int $applicationId): ?int
    {
        if (! Schema::hasTable('application_admin_assignments')) {
            return null;
        }

        return DB::table('application_admin_assignments')
            ->where('application_id', $applicationId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->count();
    }

    private function adminProfiles(int $applicationId): ?int
    {
        if (! Schema::hasTable('application_admin_profiles')) {
            return null;
        }

        return DB::table('application_admin_profiles')
            ->where('application_id', $applicationId)
            ->count();
    }

    private function countScopedTable(string $table, int $applicationId): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        if (Schema::hasColumn($table, 'app_id')) {
            return DB::table($table)->where('app_id', $applicationId)->count();
        }

        if (Schema::hasColumn($table, 'application_id')) {
            return DB::table($table)->where('application_id', $applicationId)->count();
        }

        return null;
    }
}
