<?php

namespace App\Domain\Automation\Workers;

use Illuminate\Support\Facades\DB;

final class DiscoveryCandidateQualityWorker
{
    /**
     * Identify duplicates across authorized feeds without publishing, deleting,
     * claiming ownership or modifying already-reviewed candidates.
     *
     * @return array{flagged_as_possible_duplicate:int}
     */
    public function run(int $appId): array
    {
        $limit = max(1, min(500, (int) config('automation_workers.batch_limit', 200)));

        $ids = DB::table('event_discovery_candidates as candidate')
            ->where('candidate.app_id', $appId)
            ->where('candidate.status', 'pending')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('event_discovery_candidates as other')
                    ->whereColumn('other.app_id', 'candidate.app_id')
                    ->whereColumn('other.content_key', 'candidate.content_key')
                    ->whereColumn('other.id', '!=', 'candidate.id');
            })
            ->orderBy('candidate.id')
            ->limit($limit)
            ->pluck('candidate.id');

        if ($ids->isEmpty()) {
            return ['flagged_as_possible_duplicate' => 0];
        }

        $updated = DB::table('event_discovery_candidates')
            ->where('app_id', $appId)
            ->where('status', 'pending')
            ->whereIn('id', $ids)
            ->update(['status' => 'possible_duplicate', 'updated_at' => now()]);

        return ['flagged_as_possible_duplicate' => $updated];
    }
}
