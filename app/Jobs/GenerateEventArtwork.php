<?php

namespace App\Jobs;

use App\Domain\Events\Services\EventArtworkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateEventArtwork implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 75;
    public int $uniqueFor = 21600;

    public function __construct(
        public readonly int $eventId,
        public readonly int $applicationId,
    ) {}

    public function uniqueId(): string
    {
        return $this->applicationId.':'.$this->eventId;
    }

    public function handle(EventArtworkService $artwork): void
    {
        $artwork->generate($this->eventId, $this->applicationId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
