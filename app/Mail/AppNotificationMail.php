<?php

namespace App\Mail;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AppNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public AppNotification $notification,
        public Application $application,
        public string $actionUrl,
    ) {
    }

    public function build()
    {
        $appName = trim((string) $this->application->name) ?: 'Cutinapp';
        $subject = trim((string) $this->notification->title) ?: 'Nova notificação';

        return $this
            ->subject($subject.' • '.$appName)
            ->view('emails.app-notification');
    }
}
