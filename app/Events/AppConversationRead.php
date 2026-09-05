<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppConversationRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $readerUserId,
        public array $participantUserIds,
        public int $applicationId,
        public string $readAt,
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
        return 'app.messaging.conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'application_id' => $this->applicationId,
            'conversation_id' => $this->conversationId,
            'reader_user_id' => $this->readerUserId,
            'read_at' => $this->readAt,
        ];
    }
}
