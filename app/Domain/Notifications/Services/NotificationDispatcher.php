<?php

namespace App\Domain\Notifications\Services;

use Illuminate\Support\Facades\Mail;

final class NotificationDispatcher
{
    public function sendEmail(string $recipient, ?string $recipientName, string $subject, string $body): void
    {
        Mail::raw($body, function ($mail) use ($recipient, $recipientName, $subject) {
            $mail->to($recipient, $recipientName)->subject($subject);
        });
    }
}
