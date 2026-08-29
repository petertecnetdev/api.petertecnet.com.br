<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EcosystemUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $modules, public string $action = 'updated') {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ecosystem.admin')];
    }

    public function broadcastAs(): string
    {
        return 'ecosystem.updated';
    }

    public function broadcastWith(): array
    {
        return ['modules' => array_values(array_unique($this->modules)), 'action' => $this->action, 'occurred_at' => now()->toIso8601String()];
    }
}
