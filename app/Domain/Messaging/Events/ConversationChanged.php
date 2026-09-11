<?php

namespace App\Domain\Messaging\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ConversationChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $eventName,
        public readonly array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('messaging.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'messaging.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'event' => $this->eventName,
            'payload' => $this->payload,
        ];
    }
}
