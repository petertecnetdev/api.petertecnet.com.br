<?php

namespace App\Console\Commands;

use App\Models\ApiUsageRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReportLegacyApiUsage extends Command
{
    protected $signature = 'platform:legacy-usage {--days=30 : Number of days to inspect} {--json : Emit machine-readable JSON}';
    protected $description = 'Report deprecated API consumption so legacy routes are removed only after observed zero usage.';

    public function handle(): int
    {
        $days = max(1, min((int) $this->option('days'), 3650));
        $since = now()->subDays($days);

        $rows = ApiUsageRecord::query()
            ->leftJoin('applications', 'applications.id', '=', 'api_usage_records.application_id')
            ->where('api_usage_records.environment', 'legacy')
            ->where('api_usage_records.occurred_at', '>=', $since)
            ->groupBy('api_usage_records.application_id', 'applications.slug', 'api_usage_records.method', 'api_usage_records.route')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                'applications.slug as application',
                'api_usage_records.method',
                'api_usage_records.route',
                DB::raw('COUNT(*) as requests'),
                DB::raw('MAX(api_usage_records.occurred_at) as last_seen_at'),
            ]);

        $payload = [
            'window_days' => $days,
            'since' => $since->toIso8601String(),
            'legacy_requests' => (int) $rows->sum('requests'),
            'safe_to_remove_legacy' => $rows->isEmpty(),
            'routes' => $rows->map(fn ($row) => [
                'application' => $row->application,
                'method' => $row->method,
                'route' => $row->route,
                'requests' => (int) $row->requests,
                'last_seen_at' => $row->last_seen_at,
            ])->values()->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('Peter Platform legacy API usage');
        $this->line("Window: {$days} day(s)");
        $this->line('Requests: ' . $payload['legacy_requests']);
        $this->line('Safe to remove legacy: ' . ($payload['safe_to_remove_legacy'] ? 'YES' : 'NO'));
        $this->newLine();
        $this->table(['Application', 'Method', 'Route', 'Requests', 'Last seen'], $payload['routes']);

        return self::SUCCESS;
    }
}
