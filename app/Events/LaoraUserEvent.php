<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LaoraUserEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $type,
        public array $payload = []
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('laora.user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'laora.event';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
