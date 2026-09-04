<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Exceptions\NotificationDispatchException;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class NotificationDispatcher
{
    public function sendEmail(string $recipient, ?string $recipientName, string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($mail) use ($recipient, $recipientName, $subject) {
                $mail->to($recipient, $recipientName)->subject($subject);
            });
        } catch (Throwable $e) {
            throw new NotificationDispatchException('Não foi possível entregar a notificação por e-mail.', 0, $e);
        }
    }
}
