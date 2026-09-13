<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\User;
use App\Services\ApplicationMailBrandingService;
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
    public array $mailBrand = [];

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
        $application = $this->event->application
            ?: ($this->event->app_id ? Application::query()->find($this->event->app_id) : null);

        $this->mailBrand = app(ApplicationMailBrandingService::class)->forApplication(
            $application,
            $this->appName,
            $this->appUrl
        );

        $mail = $this
            ->subject($this->notificationTitle.' • '.$this->mailBrand['name'])
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
                'appName' => $this->mailBrand['name'],
                'flyerUrl' => $this->flyerUrl,
                'shareUrl' => $this->shareUrl,
                'createEventUrl' => $this->createEventUrl,
                'mailBrand' => $this->mailBrand,
            ]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $this->mailBrand['sender_name']);
        }

        return $mail;
    }
}
