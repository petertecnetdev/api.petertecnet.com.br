<?php

namespace App\Events;

use App\Domain\Messaging\Support\AppMessagePayload;
use App\Models\AppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppMessageChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AppMessage $message,
        public string $action,
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
        return 'app.messaging.message.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'application_id' => $this->applicationId,
            'conversation_id' => (int) $this->message->conversation_id,
            'action' => $this->action,
            'message' => AppMessagePayload::make($this->message),
        ];
    }
}
