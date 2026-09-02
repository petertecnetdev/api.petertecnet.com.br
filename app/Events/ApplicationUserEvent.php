<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationUserEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $application,
        public int $userId,
        public string $type,
        public array $payload = []
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('application.' . $this->application . '.user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'application.event';
    }

    public function broadcastWith(): array
    {
        return [
            'application' => $this->application,
            'type' => $this->type,
            'payload' => $this->payload,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
