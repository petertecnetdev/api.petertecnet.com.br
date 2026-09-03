<?php

namespace App\Services\Operations;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalIssueService
{
    public function __construct(private readonly OperationalIssueClassifier $classifier)
    {
    }

    public function sync(Collection $events): void
    {
        if ($events->isEmpty() || ! $this->available()) return;

        DB::transaction(function () use ($events) {
            foreach ($events as $event) {
                if (! is_array($event)) continue;
                $interactionId = (int) ($event['id'] ?? 0);
                $fingerprint = (string) ($event['fingerprint'] ?? '');
                if ($interactionId <= 0 || $fingerprint === '') continue;

                $this->syncEvent($event, $interactionId, $fingerprint);
            }
        });
    }

    public function summary(): array
    {
        if (! $this->available()) {
            return [
                'open' => 0,
                'critical' => 0,
                'p0' => 0,
                'p1' => 0,
                'regressions' => 0,
                'resolved' => 0,
            ];
        }

        $openStatuses = ['new', 'acknowledged', 'investigating', 'fixed', 'monitoring'];
        $base = DB::table('operational_issues');

        return [
            'open' => (clone $base)->whereIn('status', $openStatuses)->count(),
            'critical' => (clone $base)->whereIn('status', $openStatuses)->where('severity', 'critical')->count(),
            'p0' => (clone $base)->whereIn('status', $openStatuses)->where('impact_score', '>=', 80)->count(),
            'p1' => (clone $base)->whereIn('status', $openStatuses)->whereBetween('impact_score', [60, 79])->count(),
            'regressions' => (clone $base)->whereIn('status', $openStatuses)->where('regression_count', '>', 0)->count(),
            'resolved' => (clone $base)->where('status', 'resolved')->count(),
        ];
    }

    public function available(): bool
    {
        return Schema::hasTable('operational_issues')
            && Schema::hasTable('operational_issue_occurrences')
            && Schema::hasTable('operational_issue_transitions');
    }

    private function syncEvent(array $event, int $interactionId, string $fingerprint): void
    {
        $occurredAt = $event['occurred_at'] ?? now();
        $category = $event['category'] ?? $this->classifier->category($event);
        $domain = $event['domain'] ?? $this->classifier->domain($event);
        $applicationId = $this->integerOrNull($event['application']['id'] ?? null);
        $userId = $this->integerOrNull($event['user']['id'] ?? null);
        $establishmentId = $this->establishmentId($event);
        $sourceVersion = $event['application']['version'] ?? $this->nested($event, ['request_context', 'application_context', 'version']);
        $sourceCommit = $this->firstNonEmpty([
            $this->nested($event, ['request_context', 'application_context', 'commit_sha']),
            $this->nested($event, ['request_context', 'application_context', 'release_sha']),
            $this->nested($event, ['request_context', 'application_context', 'deployment_sha']),
        ]);

        $issue = DB::table('operational_issues')->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if (! $issue) {
            $issueId = DB::table('operational_issues')->insertGetId([
                'fingerprint' => $fingerprint,
                'title' => Str::limit((string) ($event['message'] ?? $event['error_code'] ?? 'Problema operacional'), 250, ''),
                'category' => $category,
                'domain' => $domain,
                'status' => 'new',
                'severity' => $event['severity'] ?? 'attention',
                'impact_score' => $this->classifier->impactScore($event),
                'first_seen_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
                'latest_interaction_id' => $interactionId,
                'latest_application_id' => $applicationId,
                'latest_http_status' => $this->integerOrNull($event['http_status'] ?? null),
                'latest_error_code' => $event['error_code'] ?? null,
                'latest_method' => $event['method'] ?? null,
                'latest_route' => $event['route'] ?? $event['path'] ?? null,
                'latest_message' => $event['message'] ?? null,
                'source_version' => $sourceVersion,
                'source_commit' => $sourceCommit,
                'context' => json_encode($this->issueContext($event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->transition($issueId, null, 'new', null, 'Problema criado automaticamente a partir da telemetria.', ['automated' => true]);
            $issue = DB::table('operational_issues')->find($issueId);
        } else {
            $issueId = (int) $issue->id;
            $this->reopenIfRegression($issue, $occurredAt, $event);
        }

        $inserted = DB::table('operational_issue_occurrences')->insertOrIgnore([
            'operational_issue_id' => $issueId,
            'interaction_id' => $interactionId,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'establishment_id' => $establishmentId,
            'occurred_at' => $occurredAt,
            'http_status' => $this->integerOrNull($event['http_status'] ?? null),
            'request_id' => $event['request_id'] ?? null,
            'correlation_id' => $event['correlation_id'] ?? null,
            'duration_ms' => $this->integerOrNull($event['duration_ms'] ?? null),
            'metadata' => json_encode([
                'application' => $event['application'] ?? null,
                'user' => $event['user'] ?? null,
                'entity' => $event['entity'] ?? null,
                'category' => $category,
                'domain' => $domain,
                'route_name' => $event['route_name'] ?? null,
                'frontend_page' => $event['frontend_page'] ?? null,
                'environment' => $event['environment'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        if ($inserted > 0) {
            $this->refreshAggregate($issueId, $event, $category, $domain, $sourceVersion, $sourceCommit);
        }
    }

    private function refreshAggregate(int $issueId, array $event, string $category, string $domain, mixed $sourceVersion, mixed $sourceCommit): void
    {
        $occurrences = DB::table('operational_issue_occurrences')->where('operational_issue_id', $issueId);
        $count = (int) (clone $occurrences)->count();
        $users = (int) (clone $occurrences)->whereNotNull('user_id')->distinct()->count('user_id');
        $applications = (int) (clone $occurrences)->whereNotNull('application_id')->distinct()->count('application_id');
        $establishments = (int) (clone $occurrences)->whereNotNull('establishment_id')->distinct()->count('establishment_id');
        $firstSeen = (clone $occurrences)->min('occurred_at');
        $lastSeen = (clone $occurrences)->max('occurred_at');
        $current = DB::table('operational_issues')->find($issueId);
        if (! $current) return;

        $severity = $this->maxSeverity((string) $current->severity, (string) ($event['severity'] ?? 'normal'));
        $impactEvent = $event;
        $impactEvent['severity'] = $severity;
        $impactEvent['category'] = $category;
        $impactEvent['domain'] = $domain;
        $impact = $this->classifier->impactScore($impactEvent, $count, $users, $applications);

        DB::table('operational_issues')->where('id', $issueId)->update([
            'title' => Str::limit((string) ($event['message'] ?? $current->title), 250, ''),
            'category' => $category,
            'domain' => $domain,
            'severity' => $severity,
            'impact_score' => $impact,
            'occurrence_count' => $count,
            'users_affected_count' => $users,
            'applications_affected_count' => $applications,
            'establishments_affected_count' => $establishments,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $lastSeen,
            'latest_interaction_id' => $event['id'] ?? $current->latest_interaction_id,
            'latest_application_id' => $this->integerOrNull($event['application']['id'] ?? null) ?? $current->latest_application_id,
            'latest_http_status' => $this->integerOrNull($event['http_status'] ?? null),
            'latest_error_code' => $event['error_code'] ?? $current->latest_error_code,
            'latest_method' => $event['method'] ?? $current->latest_method,
            'latest_route' => $event['route'] ?? $event['path'] ?? $current->latest_route,
            'latest_message' => $event['message'] ?? $current->latest_message,
            'source_version' => $sourceVersion ?: $current->source_version,
            'source_commit' => $sourceCommit ?: $current->source_commit,
            'context' => json_encode($this->issueContext($event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function reopenIfRegression(object $issue, mixed $occurredAt, array $event): void
    {
        $status = (string) $issue->status;
        if (! in_array($status, ['fixed', 'monitoring', 'resolved'], true)) return;

        $boundary = match ($status) {
            'fixed' => $issue->fixed_at,
            'monitoring' => $issue->monitoring_at,
            'resolved' => $issue->resolved_at,
            default => null,
        };
        if (! $boundary) return;

        try {
            $isRegression = Carbon::parse($occurredAt)->gt(Carbon::parse($boundary));
        } catch (\Throwable) {
            $isRegression = false;
        }
        if (! $isRegression) return;

        DB::table('operational_issues')->where('id', $issue->id)->update([
            'status' => 'new',
            'regression_count' => DB::raw('regression_count + 1'),
            'resolved_at' => null,
            'fixed_at' => null,
            'monitoring_at' => null,
            'updated_at' => now(),
        ]);
        $this->transition((int) $issue->id, $status, 'new', null, 'Problema reaberto automaticamente após nova ocorrência.', [
            'automated' => true,
            'interaction_id' => $event['id'] ?? null,
        ]);
    }

    private function transition(int $issueId, ?string $from, string $to, ?int $actorId, ?string $note, array $metadata = []): void
    {
        DB::table('operational_issue_transitions')->insert([
            'operational_issue_id' => $issueId,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'note' => $note,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    }

    private function issueContext(array $event): array
    {
        return array_filter([
            'application' => $event['application'] ?? null,
            'entity' => $event['entity'] ?? null,
            'client' => $event['client'] ?? null,
            'network' => $event['network'] ?? null,
            'frontend_page' => $event['frontend_page'] ?? null,
            'route_name' => $event['route_name'] ?? null,
            'environment' => $event['environment'] ?? null,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function establishmentId(array $event): ?int
    {
        $entity = $event['entity'] ?? [];
        $type = Str::lower((string) ($entity['type'] ?? ''));
        if (Str::contains($type, 'establishment')) return $this->integerOrNull($entity['id'] ?? null);

        return $this->integerOrNull(
            $this->nested($event, ['request_context', 'application_context', 'establishment_id'])
            ?? $this->nested($event, ['request_context', 'parameters', 'establishment_id'])
        );
    }

    private function maxSeverity(string $a, string $b): string
    {
        $rank = ['normal' => 0, 'attention' => 1, 'suspicious' => 2, 'critical' => 3];
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nested(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) return null;
            $cursor = $cursor[$key];
        }
        return $cursor;
    }

    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') return $value;
        }
        return null;
    }
}
