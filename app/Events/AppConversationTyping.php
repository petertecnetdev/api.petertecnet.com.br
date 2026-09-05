<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppConversationTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public bool $isTyping,
        public array $participantUserIds,
        public int $applicationId,
    ) {
    }

    public function broadcastOn(): array
    {
        return array_map(
            static fn ($userId) => new PrivateChannel('App.Models.User.'.(int) $userId),
            array_values(array_unique(array_map('intval', $this->participantUserIds)))
        );
    }

    public function broadcastAs(): string
    {
        return 'app.messaging.conversation.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'application_id' => $this->applicationId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'is_typing' => $this->isTyping,
            'sent_at' => now()->toISOString(),
        ];
    }
}
