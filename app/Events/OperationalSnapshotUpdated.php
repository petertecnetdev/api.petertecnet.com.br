<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperationalSnapshotUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $reason = 'monitor',
        public readonly ?string $generatedAt = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ecosystem.admin')];
    }

    public function broadcastAs(): string
    {
        return 'mission-control.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'generated_at' => $this->generatedAt ?: now()->toIso8601String(),
        ];
    }
}
