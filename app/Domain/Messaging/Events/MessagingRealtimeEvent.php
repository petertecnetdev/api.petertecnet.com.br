<?php

namespace App\Domain\Messaging\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MessagingRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $eventName,
        public readonly array $payload,
        public readonly array $userIds = [],
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('messaging.conversation.'.$this->conversationId)];

        foreach (array_unique(array_filter(array_map('intval', $this->userIds))) as $userId) {
            $channels[] = new PrivateChannel('App.Models.User.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return $this->payload + ['conversation_id' => $this->conversationId];
    }
}
