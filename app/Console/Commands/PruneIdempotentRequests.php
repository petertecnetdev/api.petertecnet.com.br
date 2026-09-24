<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotentRequests extends Command
{
    protected $signature = 'idempotency:prune {--days=7 : Retain completed idempotency responses for this many days}';

    protected $description = 'Remove expired completed idempotency responses to minimize retained request data';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 90],
        ]);

        if ($days === false) {
            $this->error('The --days option must be an integer between 1 and 90.');

            return self::INVALID;
        }

        $deleted = DB::table('idempotent_requests')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} completed idempotency record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
