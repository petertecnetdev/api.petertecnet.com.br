<?php

namespace App\Domain\Social\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SocialTimelineChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $appId,
        public readonly string $action,
        public readonly int $postId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('app.'.$this->appId.'.timeline')];
    }

    public function broadcastAs(): string
    {
        return 'timeline.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'post_id' => $this->postId,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
