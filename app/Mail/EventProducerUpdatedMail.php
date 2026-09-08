<?php

namespace App\Mail;

use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EventProducerUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $owner;
    public Event $event;
    public Establishment $production;
    public string $action;
    public array $changedLabels;
    public string $notificationTitle;
    public string $notificationMessage;
    public string $appUrl;
    public string $eventUrl;
    public string $eventManagementUrl;
    public string $appName;
    public ?string $flyerUrl;
    public string $shareUrl;
    public string $createEventUrl;

    public function __construct(
        User $owner,
        Event $event,
        Establishment $production,
        string $action,
        array $changedLabels,
        string $notificationTitle,
        string $notificationMessage,
        string $appUrl,
        string $eventUrl,
        string $eventManagementUrl,
        string $appName,
        ?string $flyerUrl = null,
        string $shareUrl = '',
        string $createEventUrl = ''
    ) {
        $this->owner = $owner;
        $this->event = $event;
        $this->production = $production;
        $this->action = $action;
        $this->changedLabels = $changedLabels;
        $this->notificationTitle = $notificationTitle;
        $this->notificationMessage = $notificationMessage;
        $this->appUrl = $appUrl;
        $this->eventUrl = $eventUrl;
        $this->eventManagementUrl = $eventManagementUrl;
        $this->appName = $appName;
        $this->flyerUrl = $flyerUrl;
        $this->shareUrl = $shareUrl;
        $this->createEventUrl = $createEventUrl;
    }

    public function build()
    {
        return $this
            ->subject($this->notificationTitle)
            ->view('emails.event-producer-updated')
            ->with([
                'owner' => $this->owner,
                'event' => $this->event,
                'production' => $this->production,
                'action' => $this->action,
                'changedLabels' => $this->changedLabels,
                'notificationTitle' => $this->notificationTitle,
                'notificationMessage' => $this->notificationMessage,
                'appUrl' => $this->appUrl,
                'eventUrl' => $this->eventUrl,
                'eventManagementUrl' => $this->eventManagementUrl,
                'appName' => $this->appName,
                'flyerUrl' => $this->flyerUrl,
                'shareUrl' => $this->shareUrl,
                'createEventUrl' => $this->createEventUrl,
            ]);
    }
}
