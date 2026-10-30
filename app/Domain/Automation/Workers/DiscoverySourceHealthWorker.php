<?php

namespace App\Domain\Automation\Workers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DiscoverySourceHealthWorker
{
    /** @return array{sources:int,stale:int,errors:int} */
    public function run(int $appId): array
    {
        $sources = DB::table('event_discovery_sources')
            ->where('app_id', $appId)
            ->where('enabled', true)
            ->whereNotNull('authorized_at')
            ->select(['id', 'last_scanned_at', 'last_error'])
            ->limit(500)
            ->get();

        $stale = 0;
        $errors = 0;
        foreach ($sources as $source) {
            if ($source->last_error !== null && $source->last_error !== '') {
                $errors++;
            }

            if ($source->last_scanned_at === null
                || CarbonImmutable::parse($source->last_scanned_at)->lt(now()->subHours(3))) {
                $stale++;
            }
        }

        if ($stale > 0 || $errors > 0) {
            // Do not log feed URLs, remote response bodies or credentials.
            Log::warning('automation.discovery_sources_degraded', [
                'app_id' => $appId,
                'stale_sources' => $stale,
                'error_sources' => $errors,
            ]);
        }

        return ['sources' => $sources->count(), 'stale' => $stale, 'errors' => $errors];
    }
}
