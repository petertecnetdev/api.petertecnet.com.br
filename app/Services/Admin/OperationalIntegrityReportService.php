<?php

namespace App\Services\Admin;

use App\Models\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalIntegrityReportService
{
    public function report(Application $application, int $hours = 24): array
    {
        $hours = max(1, min($hours, 168));
        $since = now()->subHours($hours);
        $appId = (int) $application->id;

        return [
            'application' => [
                'id' => $appId,
                'name' => $application->name,
                'slug' => $application->slug,
            ],
            'window' => [
                'hours' => $hours,
                'from' => $since->toIso8601String(),
                'to' => now()->toIso8601String(),
            ],
            'read_only' => true,
            'summary' => [
                'pending_payments' => $this->pendingPayments($appId, $since),
                'failed_payments' => $this->failedPayments($appId, $since),
                'unreconciled_payments' => $this->unreconciledPayments($appId, $since),
                'expired_pending_orders' => $this->expiredPendingOrders($appId),
                'open_high_severity_issues' => $this->openHighSeverityIssues($appId),
            ],
            'checks' => [
                'payment_data_is_application_scoped' => Schema::hasTable('ecosystem_payments'),
                'order_data_is_application_scoped' => Schema::hasTable('commerce_orders'),
                'report_does_not_mutate_data' => true,
            ],
            'next_steps' => [
                'review_payment_failures_before_manual retry',
                'reconcile payment records through versioned workflow only',
                'investigate expired orders before any customer-facing correction',
            ],
        ];
    }

    private function pendingPayments(int $appId, Carbon $since): int
    {
        if (! Schema::hasTable('ecosystem_payments')) return 0;

        return (int) DB::table('ecosystem_payments')
            ->where('app_id', $appId)
            ->whereIn('status', ['pending', 'processing', 'in_review'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function failedPayments(int $appId, Carbon $since): int
    {
        if (! Schema::hasTable('ecosystem_payments')) return 0;

        return (int) DB::table('ecosystem_payments')
            ->where('app_id', $appId)
            ->whereIn('status', ['failed', 'declined', 'cancelled'])
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function unreconciledPayments(int $appId, Carbon $since): int
    {
        if (! Schema::hasTable('ecosystem_payments')) return 0;

        return (int) DB::table('ecosystem_payments')
            ->where('app_id', $appId)
            ->whereIn('status', ['approved', 'paid'])
            ->where(function ($query) {
                $query->whereNull('reconciliation_status')
                    ->orWhereNotIn('reconciliation_status', ['reconciled', 'settled']);
            })
            ->where('created_at', '>=', $since)
            ->count();
    }

    private function expiredPendingOrders(int $appId): int
    {
        if (! Schema::hasTable('commerce_orders')) return 0;

        return (int) DB::table('commerce_orders')
            ->where('app_id', $appId)
            ->whereIn('status', ['pending', 'awaiting_payment', 'processing'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();
    }

    private function openHighSeverityIssues(int $appId): int
    {
        if (! Schema::hasTable('operational_issues')) return 0;

        return (int) DB::table('operational_issues')
            ->where(function ($query) use ($appId) {
                $query->where('application_id', $appId)
                    ->orWhere('latest_application_id', $appId);
            })
            ->whereIn('status', ['open', 'investigating', 'acknowledged'])
            ->whereIn('severity', ['high', 'critical'])
            ->count();
    }
}
