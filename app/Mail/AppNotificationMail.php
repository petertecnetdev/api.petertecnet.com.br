<?php

namespace App\Mail;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationMailBrandingService;
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
        $mailBrand = app(ApplicationMailBrandingService::class)->forApplication($this->application);
        $subject = trim((string) $this->notification->title) ?: 'Nova notificação';

        $mail = $this
            ->subject($subject.' • '.$mailBrand['name'])
            ->view('emails.app-notification')
            ->with(['mailBrand' => $mailBrand]);

        $fromAddress = trim((string) config('mail.from.address'));
        if ($fromAddress !== '') {
            $mail->from($fromAddress, $mailBrand['sender_name']);
        }

        return $mail;
    }
}
