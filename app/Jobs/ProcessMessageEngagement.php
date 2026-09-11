<?php

namespace App\Jobs;

use App\Domain\Messaging\Services\MessageEngagementService;
use App\Models\Application;
use App\Support\ApplicationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMessageEngagement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [10, 30, 90, 180];

    public function __construct(
        public int $appId,
        public int $messageId,
        public int $recipientUserId,
    ) {
    }

    public function handle(ApplicationContext $context, MessageEngagementService $service): void
    {
        $application = Application::query()->find($this->appId);
        if (! $application) {
            return;
        }

        $context->set($application);
        $service->processMessage($this->messageId, $this->recipientUserId);
    }
}
