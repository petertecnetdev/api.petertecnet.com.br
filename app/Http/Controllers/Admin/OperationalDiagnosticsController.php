<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationalIssueClassifier;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationalDiagnosticsController extends Controller
{
    public function __construct(
        private readonly OperationalIssueClassifier $classifier,
        private readonly OperationalIssueService $issues,
        private readonly OperationalTelemetryService $telemetry,
    ) {
    }

    public function security(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $hours = max(1, min((int) $request->integer('hours', 24), 168));
        $limit = max(50, min((int) $request->integer('limit', 250), 500));
        if (! $this->telemetry->available()) return response()->json($this->emptyPayload($hours));

        $counts = $this->telemetry->counts($hours);
        $syncEvents = $this->telemetry->events(max(48, $hours * 2), 1500);
        $this->issues->sync($syncEvents);

        $sinceTimestamp = now()->subHours($hours)->timestamp;
        $events = $syncEvents
            ->filter(fn (array $event) => $event['occurred_at'] && strtotime((string) $event['occurred_at']) >= $sinceTimestamp)
            ->take($limit)
            ->values();

        $groupCounts = $events->countBy('fingerprint');
        $events = $events->map(function (array $event) use ($groupCounts) {
            $event['occurrence_count'] = (int) ($groupCounts[$event['fingerprint']] ?? 1);
            return $event;
        });

        $groups = $events
            ->groupBy('fingerprint')
            ->map(function ($rows, string $fingerprint) {
                $latest = $rows->first();
                $users = $rows->pluck('user.id')->filter()->unique()->count();
                $apps = $rows->pluck('application.id')->filter()->unique()->count();
                $severity = $rows->contains(fn ($event) => $event['severity'] === 'critical')
                    ? 'critical'
                    : ($rows->contains(fn ($event) => $event['severity'] === 'suspicious') ? 'suspicious' : ($latest['severity'] ?? 'attention'));
                $impactEvent = $latest;
                $impactEvent['severity'] = $severity;
                $impact = $this->classifier->impactScore($impactEvent, $rows->count(), $users, $apps);

                return [
                    'fingerprint' => $fingerprint,
                    'occurrences' => $rows->count(),
                    'first_seen_at' => $rows->last()['occurred_at'] ?? null,
                    'last_seen_at' => $latest['occurred_at'] ?? null,
                    'severity' => $severity,
                    'category' => $latest['category'] ?? 'operational',
                    'domain' => $latest['domain'] ?? 'platform',
                    'impact_score' => $impact,
                    'priority' => $this->classifier->priority($impact),
                    'http_status' => $latest['http_status'] ?? null,
                    'error_code' => $latest['error_code'] ?? null,
                    'message' => $latest['message'] ?? null,
                    'application' => $latest['application'] ?? null,
                    'method' => $latest['method'] ?? null,
                    'route' => $latest['route'] ?? null,
                    'sample_request_id' => $latest['request_id'] ?? null,
                    'technical' => $latest['technical'] ?? null,
                ];
            })
            ->sortByDesc('impact_score')
            ->values();

        $impactedApps = $events
            ->map(fn ($event) => $event['application']['slug'] ?? $event['application']['name'] ?? null)
            ->filter()->unique()->values();

        return response()->json([
            'diagnostics_version' => 3,
            'window_hours' => $hours,
            'critical_events_24h' => $counts['critical'],
            'suspicious_24h' => $counts['suspicious'],
            'attention_24h' => $counts['attention'],
            'denied_24h' => $counts['denied'],
            'errors_24h' => $counts['errors'],
            'total_relevant_events' => $counts['relevant'],
            'unique_issues' => $groups->count(),
            'repeated_events' => max(0, $events->count() - $groups->count()),
            'impacted_applications' => $impactedApps,
            'operational_issues' => $this->issues->summary(),
            'truncated' => $counts['relevant'] > $events->count(),
            'groups' => $groups,
            'events' => $events,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasProfile('Administrador') || $user->hasPermission('security_view') || $user->hasPermission('ecosystem_manage')),
            403,
            'Usuário sem permissão para acessar diagnósticos operacionais.'
        );
    }

    private function emptyPayload(int $hours): array
    {
        return [
            'diagnostics_version' => 3,
            'window_hours' => $hours,
            'critical_events_24h' => 0,
            'suspicious_24h' => 0,
            'attention_24h' => 0,
            'denied_24h' => 0,
            'errors_24h' => 0,
            'total_relevant_events' => 0,
            'unique_issues' => 0,
            'repeated_events' => 0,
            'impacted_applications' => [],
            'operational_issues' => $this->issues->summary(),
            'truncated' => false,
            'groups' => [],
            'events' => [],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
