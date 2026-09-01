<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AppNotification $notification)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'app.notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => $this->notification->only([
                'id', 'app_id', 'user_id', 'type', 'title', 'message',
                'reference_type', 'reference_id', 'reference_url', 'data',
                'read_at', 'created_at',
            ]),
        ];
    }
}
