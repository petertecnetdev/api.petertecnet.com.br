<?php

namespace App\Jobs;

use App\Domain\EventDiscovery\Services\DiscoveryFeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ScanEventDiscoverySource implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return 'event-discovery-source-'.$this->sourceId;
    }

    public function handle(DiscoveryFeedService $feeds): void
    {
        if (! config('event_discovery.enabled', false)) return;

        $source = DB::table('event_discovery_sources')
            ->where('id', $this->sourceId)->where('enabled', true)->first();
        if (! $source) return;

        try {
            $feeds->scan($source);
        } catch (Throwable $exception) {
            DB::table('event_discovery_sources')->where('id', $this->sourceId)->update([
                'last_error' => Str::limit($exception->getMessage(), 500, ''),
                'last_scanned_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }
    }
}
