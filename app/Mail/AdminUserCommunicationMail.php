<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminUserCommunicationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public string $mailSubject,
        public string $body,
        public ?string $actionUrl = null,
    ) {
    }

    public function build()
    {
        return $this
            ->subject($this->mailSubject)
            ->view('emails.admin-user-communication');
    }
}
