<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotentRequests extends Command
{
    protected $signature = 'idempotency:prune {--days=7 : Retain completed idempotency responses for this many days} {--batch=500 : Maximum records deleted per batch}';

    protected $description = 'Remove expired completed idempotency responses to minimize retained request data';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 90],
        ]);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 50, 'max_range' => 5000],
        ]);

        if ($days === false) {
            $this->error('The --days option must be an integer between 1 and 90.');

            return self::INVALID;
        }

        if ($batch === false) {
            $this->error('The --batch option must be an integer between 50 and 5000.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table('idempotent_requests')
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deletedThisBatch = DB::table('idempotent_requests')
                ->whereIn('id', $ids)
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->delete();

            $deleted += $deletedThisBatch;
        } while ($ids->count() === $batch);

        $this->info("Pruned {$deleted} completed idempotency record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
