<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MonitorProductionOnboarding extends Command
{
    protected $signature = 'operations:monitor-production-onboarding';

    protected $description = 'Detect regressions in the production creation funnel and surface them as operational issues.';

    private const FINGERPRINT = 'funnel:cutinapp:production-create:error-rate';

    public function handle(): int
    {
        if (! Schema::hasTable('applications') || ! Schema::hasTable('interactions') || ! Schema::hasTable('operational_issues')) {
            $this->warn('Production onboarding telemetry tables are not available yet.');
            return self::SUCCESS;
        }

        $application = DB::table('applications')->where('slug', 'cutinapp')->first(['id', 'slug', 'version']);
        if (! $application) {
            $this->warn('Cutinapp application context was not found.');
            return self::SUCCESS;
        }

        $now = now();
        $currentStart = $now->copy()->subMinutes(15);
        $baselineStart = $now->copy()->subMinutes(75);

        $base = DB::table('interactions')
            ->where('app_id', $application->id)
            ->where('method', 'POST')
            ->where('route', 'like', '%organizations%');

        $current = (clone $base)->where('created_at', '>=', $currentStart);
        $currentTotal = (clone $current)->count();
        $currentErrors = (clone $current)->where('outcome', 'error')->count();
        $currentRate = $currentTotal > 0 ? round(($currentErrors / $currentTotal) * 100, 2) : 0.0;

        $baseline = (clone $base)
            ->where('created_at', '>=', $baselineStart)
            ->where('created_at', '<', $currentStart);
        $baselineTotal = (clone $baseline)->count();
        $baselineErrors = (clone $baseline)->where('outcome', 'error')->count();
        $baselineRate = $baselineTotal > 0 ? round(($baselineErrors / $baselineTotal) * 100, 2) : 0.0;

        $regressed = $currentTotal >= 5
            && $currentErrors >= 3
            && $currentRate >= 20.0
            && ($currentRate >= 35.0 || $currentRate >= ($baselineRate + 10.0));

        if ($regressed) {
            $this->openOrRefreshIssue((int) $application->id, (string) ($application->version ?? ''), [
                'current_total' => $currentTotal,
                'current_errors' => $currentErrors,
                'current_error_rate' => $currentRate,
                'baseline_total' => $baselineTotal,
                'baseline_errors' => $baselineErrors,
                'baseline_error_rate' => $baselineRate,
                'window_minutes' => 15,
            ]);
            $this->error("Production onboarding regression detected: {$currentErrors}/{$currentTotal} errors ({$currentRate}%).");
            return self::SUCCESS;
        }

        if ($currentTotal >= 5) {
            $this->resolveIssue([
                'current_total' => $currentTotal,
                'current_errors' => $currentErrors,
                'current_error_rate' => $currentRate,
                'baseline_error_rate' => $baselineRate,
            ]);
        }

        $this->info("Production onboarding healthy: {$currentErrors}/{$currentTotal} errors ({$currentRate}%).");
        return self::SUCCESS;
    }

    private function openOrRefreshIssue(int $applicationId, string $sourceVersion, array $context): void
    {
        $existing = DB::table('operational_issues')->where('fingerprint', self::FINGERPRINT)->first();
        $critical = (float) $context['current_error_rate'] >= 60.0;
        $data = [
            'application_id' => $applicationId,
            'category' => 'application_error',
            'domain' => 'producer_onboarding',
            'title' => 'Regressão na criação de produções',
            'description' => 'A taxa de erro do cadastro de produção ultrapassou o limite e piorou em relação à janela anterior.',
            'severity' => $critical ? 'critical' : 'warning',
            'priority' => $critical ? 'P0' : 'P1',
            'status' => 'new',
            'source' => 'production_onboarding_monitor',
            'impact_score' => $critical ? 95 : 78,
            'applications_affected_count' => 1,
            'latest_method' => 'POST',
            'latest_route' => 'api/v1/apps/{application}/organizations',
            'latest_http_status' => 500,
            'latest_error_code' => 'PRODUCTION_CREATE_ERROR_RATE',
            'latest_message' => sprintf(
                '%d erros em %d tentativas nos últimos 15 minutos (%.2f%%; baseline %.2f%%).',
                $context['current_errors'],
                $context['current_total'],
                $context['current_error_rate'],
                $context['baseline_error_rate'],
            ),
            'source_version' => $sourceVersion ?: null,
            'last_seen_at' => now(),
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ];

        if (! $existing) {
            $issueId = DB::table('operational_issues')->insertGetId(array_merge($data, [
                'public_id' => 'OPS-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'fingerprint' => self::FINGERPRINT,
                'occurrence_count' => 1,
                'regression_count' => 0,
                'users_affected_count' => 0,
                'establishments_affected_count' => 0,
                'first_seen_at' => now(),
                'created_at' => now(),
            ]));
        } else {
            $regression = in_array($existing->status, ['resolved', 'ignored'], true);
            $issueId = (int) $existing->id;
            $data['occurrence_count'] = ((int) $existing->occurrence_count) + 1;
            if ($regression) $data['regression_count'] = ((int) $existing->regression_count) + 1;
            $data['resolved_at'] = null;
            DB::table('operational_issues')->where('id', $issueId)->update($data);
        }

        if (Schema::hasTable('operational_issue_occurrences')) {
            DB::table('operational_issue_occurrences')->insert([
                'issue_id' => $issueId,
                'application_id' => $applicationId,
                'method' => 'POST',
                'route' => 'api/v1/apps/{application}/organizations',
                'http_status' => 500,
                'error_code' => 'PRODUCTION_CREATE_ERROR_RATE',
                'message' => $data['latest_message'],
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function resolveIssue(array $context): void
    {
        $issue = DB::table('operational_issues')->where('fingerprint', self::FINGERPRINT)->first();
        if (! $issue || in_array($issue->status, ['resolved', 'ignored', 'expected'], true)) return;

        DB::table('operational_issues')->where('id', $issue->id)->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'latest_message' => sprintf(
                'Fluxo recuperado: %d erros em %d tentativas (%.2f%%).',
                $context['current_errors'],
                $context['current_total'],
                $context['current_error_rate'],
            ),
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('operational_issue_history')) {
            DB::table('operational_issue_history')->insert([
                'issue_id' => $issue->id,
                'from_status' => $issue->status,
                'to_status' => 'resolved',
                'note' => 'Taxa de erro do cadastro voltou ao limite operacional.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
