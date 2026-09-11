<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MessageEngagementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public Application $application,
        public array $conversations,
        public string $actionUrl,
        public string $kind,
        public int $messageCount,
    ) {}

    public function build()
    {
        $conversationCount = count($this->conversations);
        $sender = $this->conversations[0]['sender_name'] ?? 'Alguém';

        if ($this->kind === 'reminder_2') {
            $subject = $conversationCount === 1
                ? $sender.' ainda está esperando sua resposta'
                : 'Você ainda tem '.$this->messageCount.' mensagens aguardando resposta';
        } elseif ($this->kind === 'reminder_1') {
            $subject = $conversationCount === 1
                ? 'Você ainda não viu a mensagem de '.$sender
                : 'Você ainda tem '.$this->messageCount.' mensagens não lidas';
        } elseif ($conversationCount > 1) {
            $subject = 'Você tem '.$this->messageCount.' novas mensagens de '.$conversationCount.' conversas';
        } elseif ($this->messageCount > 1) {
            $subject = $sender.' enviou '.$this->messageCount.' novas mensagens';
        } else {
            $subject = $sender.' enviou uma mensagem';
        }

        return $this
            ->subject($subject.' • '.($this->application->name ?: 'Cutinapp'))
            ->view('emails.message-engagement');
    }
}
