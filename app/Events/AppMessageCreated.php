<?php

namespace App\Events;

use App\Models\AppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AppMessage $message,
        public array $participantUserIds,
        public int $applicationId,
    ) {
        $this->message->loadMissing('sender:id,first_name,last_name,user_name,avatar');
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
        return 'app.messaging.message.created';
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'application_id' => $this->applicationId,
            'conversation_id' => (int) $this->message->conversation_id,
            'message' => [
                'id' => (int) $this->message->id,
                'conversation_id' => (int) $this->message->conversation_id,
                'sender_user_id' => (int) $this->message->sender_user_id,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'metadata' => $this->message->metadata,
                'created_at' => optional($this->message->created_at)->toISOString(),
                'edited_at' => optional($this->message->edited_at)->toISOString(),
                'sender' => $sender ? [
                    'id' => (int) $sender->id,
                    'first_name' => $sender->first_name,
                    'last_name' => $sender->last_name,
                    'user_name' => $sender->user_name,
                    'avatar' => $sender->avatar,
                ] : null,
            ],
        ];
    }
}
