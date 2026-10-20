<?php

namespace App\Domain\Automation\Workers;

use Illuminate\Support\Facades\DB;

final class CandidateFreshnessWorker
{
    /**
     * Measure candidates requiring human review without changing their ownership,
     * publication status, event data or authorized source evidence.
     *
     * @return array<string,int>
     */
    public function run(int $appId): array
    {
        $base = DB::table('event_discovery_candidates')->where('app_id', $appId);
        $unreviewed = (clone $base)->whereIn('status', ['pending', 'possible_duplicate']);
        return [
            'pending_review' => (clone $unreviewed)->count(),
            'past_event_candidates' => (clone $unreviewed)->where('start_at', '<', now())->count(),
            'not_seen_in_seven_days' => (clone $unreviewed)->where('last_seen_at', '<', now()->subDays(7))->count(),
        ];
    }
}
