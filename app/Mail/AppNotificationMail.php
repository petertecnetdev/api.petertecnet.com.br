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
        public AppNotification $notification,
        public User $recipientUser,
        public Application $application,
    ) {}

    public function build()
    {
        $title = $this->notification->title ?: 'Nova notificação';

        return $this
            ->subject('Kryvion · '.$title)
            ->view('emails.app-notification');
    }
}
