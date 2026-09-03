<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-side resilience for Mission Control issue intelligence.
 *
 * A partially migrated observability schema must not make the whole command
 * center unavailable. Mutating operations continue to use the strict parent
 * implementation so writes are never silently accepted against an invalid
 * schema.
 */
class ResilientOperationalIssueService extends OperationalIssueService
{
    public function list(Request $request): array
    {
        try {
            return parent::list($request);
        } catch (Throwable $exception) {
            $this->recordFailure('issues_list', $exception);

            return [
                'data' => [],
                'summary' => [
                    'open' => 0,
                    'critical' => 0,
                    'p0' => 0,
                    'p1' => 0,
                    'regressions' => 0,
                    'resolved' => 0,
                ],
                'filters' => [
                    'categories' => [],
                    'domains' => [],
                ],
                'degraded' => true,
            ];
        }
    }

    public function intelligence(): array
    {
        try {
            if (! $this->hasIntelligenceSchema()) {
                $this->recordSchemaDrift('issue_intelligence');

                return $this->degradedIntelligence();
            }

            return array_merge(parent::intelligence(), ['degraded' => false]);
        } catch (Throwable $exception) {
            $this->recordFailure('issue_intelligence', $exception);

            return $this->degradedIntelligence();
        }
    }

    private function hasIntelligenceSchema(): bool
    {
        if (! Schema::hasTable('operational_issues')) {
            return false;
        }

        foreach (['id', 'fingerprint', 'status', 'priority', 'title', 'impact_score', 'repair_plan'] as $column) {
            if (! Schema::hasColumn('operational_issues', $column)) {
                return false;
            }
        }

        return true;
    }

    private function degradedIntelligence(): array
    {
        return [
            'summary' => [
                'active_alerts' => 0,
                'critical_alerts' => 0,
                'deployments_24h' => 0,
                'repair_plans' => 0,
            ],
            'alerts' => [],
            'deployments' => [],
            'slos' => [],
            'degraded' => true,
        ];
    }

    private function recordSchemaDrift(string $component): void
    {
        Log::warning('mission_control.issue_component_schema_drift', [
            'component' => $component,
        ]);
    }

    private function recordFailure(string $component, Throwable $exception): void
    {
        Log::warning('mission_control.issue_component_failed', [
            'component' => $component,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
