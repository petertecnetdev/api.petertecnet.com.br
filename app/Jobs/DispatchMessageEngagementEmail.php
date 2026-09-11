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

class DispatchMessageEngagementEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public int $appId,
        public int $userId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(ApplicationContext $context, MessageEngagementService $service): void
    {
        $application = Application::query()->find($this->appId);
        if (! $application) {
            return;
        }

        $context->set($application);
        $service->sendDueEmail($this->userId);
    }
}
