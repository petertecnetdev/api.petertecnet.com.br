<?php

namespace App\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

final class ResilientRealtimePublisher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(object $event, array $context = []): bool
    {
        try {
            $this->events->dispatch($event);

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('Realtime broadcast failed; primary operation preserved.', array_merge($context, [
                'event' => $event::class,
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, 500),
            ]));

            return false;
        }
    }
}
