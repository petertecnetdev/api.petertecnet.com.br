<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Facades\DB;

final class LeaseRevisionService
{
    public function snapshot(int $appId, int $leaseId): array
    {
        $lease = DB::table('leases')->where('app_id', $appId)->where('id', $leaseId)->first();
        if (! $lease) return [];

        $signatures = DB::table('lease_signatures')->where('app_id', $appId)->where('lease_id', $leaseId)
            ->orderBy('party')->get(['party', 'signature_hash', 'provider', 'provider_envelope_id', 'signed_at'])
            ->map(fn ($row) => (array) $row)->all();

        $documents = DB::table('lease_documents')->where('app_id', $appId)->where('lease_id', $leaseId)
            ->orderBy('id')->get(['id', 'category', 'name', 'sha256', 'status', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();

        return [
            'lease' => (array) $lease,
            'signatures' => $signatures,
            'documents' => $documents,
        ];
    }

    public function record(int $appId, int $leaseId, ?int $actorUserId, string $eventType, ?array $before = null, ?array $after = null): ?array
    {
        $after ??= $this->snapshot($appId, $leaseId);
        if ($after === []) return null;

        $canonical = $this->canonicalJson($after);
        $hash = hash('sha256', $canonical);
        $last = DB::table('lease_revisions')->where('app_id', $appId)->where('lease_id', $leaseId)->orderByDesc('sequence')->first();
        if ($last && hash_equals((string) $last->snapshot_sha256, $hash) && $eventType !== 'signature') return (array) $last;

        $sequence = ((int) ($last->sequence ?? 0)) + 1;
        $changes = $before === null ? null : $this->topLevelChanges($before, $after);
        $id = DB::table('lease_revisions')->insertGetId([
            'app_id' => $appId,
            'lease_id' => $leaseId,
            'actor_user_id' => $actorUserId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'snapshot' => $canonical,
            'changes' => $changes ? $this->canonicalJson($changes) : null,
            'snapshot_sha256' => $hash,
            'created_at' => now(),
        ]);

        return (array) DB::table('lease_revisions')->where('id', $id)->first();
    }

    public function verify(object|array $revision): bool
    {
        $snapshot = is_array($revision) ? ($revision['snapshot'] ?? null) : ($revision->snapshot ?? null);
        $expected = is_array($revision) ? ($revision['snapshot_sha256'] ?? '') : ($revision->snapshot_sha256 ?? '');
        if ($snapshot === null || $expected === '') return false;
        $decoded = is_array($snapshot) ? $snapshot : json_decode((string) $snapshot, true);
        if (! is_array($decoded)) return false;
        return hash_equals((string) $expected, hash('sha256', $this->canonicalJson($decoded)));
    }

    private function topLevelChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }
        return $changes;
    }

    private function canonicalJson(array $value): string
    {
        $value = $this->sortRecursive($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) return array_map(fn ($item) => is_array($item) ? $this->sortRecursive($item) : $item, $value);
        ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->sortRecursive($item);
        return $value;
    }
}
